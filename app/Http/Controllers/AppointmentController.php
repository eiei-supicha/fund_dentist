<?php

namespace App\Http\Controllers;

use App\Models\Appointment;
use App\Models\Dentist;
use App\Models\Patient;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Illuminate\Database\Eloquent\Builder;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Psr\Http\Message\ServerRequestInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Gate;
use Barryvdh\DomPDF\Facade\Pdf;

class AppointmentController extends SearchableController
{
    const MAX_ITEMS = 5;

    #[\Override]
    function getQuery(): Builder
    {
        return Appointment::query()
            ->orderBy('appointment_date')
            ->orderBy('appointment_time');
    }

    #[\Override]
    function applyWhereToFilterByTerm(Builder $query, string $word): void
    {
        $query->where('description', 'like', "%{$word}%")
            ->orWhere('appointment_code', 'like', "%{$word}%")
            ->orWhereHas('patient', function (Builder $patientQuery) use ($word) {
                $patientQuery->where('patient_name', 'like', "%{$word}%");
            })
            ->orWhereHas('dentist', function (Builder $dentistQuery) use ($word) {
                $dentistQuery->where('dentist_name', 'like', "%{$word}%");
            });
    }

    function list(ServerRequestInterface $request): View
    {

        $criteria = $this->prepareCriteria($request->getQueryParams());
        $query = $this->search($criteria);
        // --- เพิ่ม filter ตาม appointment_date ---
        if (!empty($criteria['date'])) {
            $query->whereDate('appointment_date', $criteria['date']);
        }

        $user = Auth::user();
        if ($user->isDentist()) {
            $query->where('dentist_id', $user->dentist_id);
        } elseif ($user->isPatient()) {
            $query->where('patient_id', $user->patient_id);
        }

        // Pagination ก่อนดึง Collection
        $paginatedAppointments = $query->paginate(self::MAX_ITEMS);

        // Group appointments ตามวัน (ใช้ Collection จาก paginate)
        $appointmentsByDate = $paginatedAppointments->getCollection()
            ->groupBy(function ($appointment) {
                return Carbon::parse($appointment->appointment_date)->toDateString();
            });

        // คืนค่า view
        return view('appointments.list', [
            'appointmentsByDate' => $appointmentsByDate,
            'criteria' => $criteria,
            'paginatedAppointments' => $paginatedAppointments,
        ]);
    }

    function view(Appointment $appointment): View
    {
        Gate::authorize('view', $appointment);

        return view('appointments.view', [
            'appointment' => $appointment,
        ]);
    }

    public function showCreateForm(): View
    {
        $dentists = Dentist::orderBy('dentist_name')->get();
        $patients = Patient::orderBy('patient_code')->get();

        Gate::authorize('create', Appointment::class);

        return view('appointments.create-form', [
            'dentists' => $dentists,
            'patients' => $patients,
        ]);
    }

    public function create(ServerRequestInterface $request): RedirectResponse
    {
        Gate::authorize('create', Appointment::class);

        try {
            $data = $request->getParsedBody();

            $dentist = Dentist::find($data['dentist_id']);
            $patient = Patient::find($data['patient_id']);

            // --- ตรวจสอบ conflict ---
            $conflict = Appointment::where('appointment_date', $data['appointment_date'])
            ->where('appointment_time', $data['appointment_time'])
            ->where(function ($query) use ($dentist, $patient) {
                // 2. ตรวจสอบว่า "หมอ" ไม่ว่าง หรือ "คนไข้" ไม่ว่าง
                $query->where('dentist_id', $dentist->dentist_id)
                    ->orWhere('patient_id', $patient->patient_id);
            })
            ->first(); // .first() เพื่อดึงข้อมูลแถวที่ชนมาเลย

            if ($conflict) {
                // ถ้ามีนัดซ้อน ให้เช็กว่าเป็นความผิดของใคร
                if ($conflict->dentist_id == $dentist->dentist_id) {
                    // ซ้อนเพราะ "หมอ" ไม่ว่าง
                    return redirect()->back()->withInput()->withErrors([
                        'alert' => 'This time slot overlaps with another appointment for the dentist (each appointment takes 2 hours)'
                    ]);
                } else {
                    // ซ้อนเพราะ "คนไข้" ไม่ว่าง (ไปจองกับหมอคนอื่นไว้)
                    return redirect()->back()->withInput()->withErrors([
                        'alert' => 'The patient already has an overlapping appointment at this time on this day.'
                    ]);
                }
            }
            $appointment = new Appointment();
            $appointment->fill($data);
            $appointment->dentist()->associate($dentist);
            $appointment->patient()->associate($patient);
            $appointment->save();

            return redirect(session('bookmarks.appointments.create-form') ?? route('appointments.list'))
                ->with('status', "Appointment {$appointment->appointment_code} was created");
        } catch (QueryException $excp) {
            return redirect()->back()->withInput()->withErrors([
                'alert' => $excp->errorInfo[2],
            ]);
        }
    }

    public function showUpdateForm(Appointment $appointment): View
    {
        $dentists = Dentist::orderBy('dentist_name')->get();
        $patients = Patient::orderBy('patient_code')->get();

        Gate::authorize('update', $appointment);

        return view('appointments.update-form', [
            'appointment' => $appointment,
            'dentists' => $dentists,
            'patients' => $patients,
        ]);
    }

    public function update(ServerRequestInterface $request, Appointment $appointment): RedirectResponse
    {
        Gate::authorize('update', $appointment);
        try {
            $data = $request->getParsedBody();

            // หา dentist และ patient ตาม ID
            $dentist = Dentist::find($data['dentist_id']);
            $patient = Patient::find($data['patient_id']);

            // ตรวจสอบว่ามีการเปลี่ยนแปลงช่วงเวลา (วัน, เวลา, หรือทันตแพทย์)
            $timeSlotChanged = $data['appointment_date'] != $appointment->appointment_date ||
            $data['appointment_time'] != $appointment->appointment_time ||
            $data['dentist_id'] != $appointment->dentist_id ||
            $data['patient_id'] != $appointment->patient_id;

            if ($timeSlotChanged) {
                $conflict = Appointment::where('appointment_date', $data['appointment_date'])
                ->where('appointment_time', $data['appointment_time'])
                ->where('appointment_id', '!=', $appointment->appointment_id) // <-- สำคัญมาก: ต้องไม่เช็กกับนัดหมายตัวนี้เอง
                ->where(function ($query) use ($dentist, $patient) {
                    // 2. ตรวจสอบว่า "หมอ" ไม่ว่าง หรือ "คนไข้" ไม่ว่าง
                    $query->where('dentist_id', $dentist->dentist_id)
                          ->orWhere('patient_id', $patient->patient_id);
                })
                ->first(); // .first() เพื่อดึงข้อมูลแถวที่ชนมาเลย

            if ($conflict) {
                if ($conflict->dentist_id == $dentist->dentist_id) {
                    // ซ้อนเพราะ "หมอ" ไม่ว่าง
                    return redirect()->back()->withInput()->withErrors([
                        'alert' => 'This time slot overlaps with another appointment for the dentist (each appointment takes 2 hours)'
                    ]);
                } else {
                    // ซ้อนเพราะ "คนไข้" ไม่ว่าง (ไปจองกับหมอคนอื่นไว้)
                    return redirect()->back()->withInput()->withErrors([
                        'alert' => 'The patient already has an overlapping appointment at this time on this day.'
                    ]);
                }
            }
        }
            // อัปเดตข้อมูล appointment
            $appointment->fill($data);
            $appointment->dentist()->associate($dentist);
            $appointment->patient()->associate($patient);
            $appointment->save();

            return redirect()->route('appointments.view', ['appointment' => $appointment->appointment_id,])
                ->with('status', "Appointment {$appointment->code} was updated");
        } catch (QueryException $excp) {
            return redirect()->back()->withInput()->withErrors([
                'alert' => $excp->errorInfo[2],
            ]);
        }
    }

    function delete(Appointment $appointment): RedirectResponse
    {
        Gate::authorize('delete', $appointment);

        try {
            $appointment->delete();

            return redirect(session('bookmarks.appointments.view') ?? route('appointments.list'))
                ->with('status', "Appointment {$appointment->code} was deleted");
        } catch (QueryException $excp) {
            // We don't want withInput() here.
            return redirect()->back()->withErrors([
                'alert' => $excp->errorInfo[2],
            ]);
        }
    }

    public function downloadPdf(Appointment $appointment)
    {
        // สร้าง PDF  และส่งข้อมูล appointment ไปด้วย
        $pdf = Pdf::loadView('appointments.pdf', [
            'appointment' => $appointment
        ]);

        // แสดงผล PDF ในเบราว์เซอร์โดยตรง (ไม่ได้บังคับดาวน์โหลด) และ ชื่อไฟล์ที่ดาวน์โหลด
        return $pdf->stream('appointment-slip-' . $appointment->appointment_id . '.pdf');
    }
}
