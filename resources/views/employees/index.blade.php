@extends('layouts.app')

@section('title', 'Karyawan')
@section('heading', 'Data Karyawan')
@section('subheading', 'Kelola data karyawan. Hanya karyawan aktif yang dinilai.')

@section('content')
<div x-data="employeeManager({
        storeUrl: '{{ route('employees.store') }}',
        updateUrl: '{{ route('employees.update', '__ID__') }}',
        reopen: @js($errors->any() ? old('_mode') : null),
        old: @js(old()),
     })">

    {{-- Toolbar --}}
    <div class="mb-4 flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
        <form method="GET" action="{{ route('employees.index') }}" class="flex flex-1 flex-col gap-2 sm:flex-row">
            <input type="search" name="q" value="{{ request('q') }}" placeholder="Cari nama, NIK atau seksi" aria-label="Cari"
                   class="w-full rounded border border-slate-300 bg-white px-3 py-2 text-sm focus:border-teal-600 focus:outline-none focus:ring-2 focus:ring-teal-600/30 sm:max-w-xs">
            <select name="department" class="rounded border border-slate-300 bg-white px-3 py-2 text-sm focus:border-teal-600 focus:outline-none focus:ring-2 focus:ring-teal-600/30">
                <option value="">Semua departemen</option>
                @foreach ($departments as $department)
                    <option value="{{ $department }}" @selected(request('department') === $department)>{{ $department }}</option>
                @endforeach
            </select>
            <select name="assessment" class="rounded border border-slate-300 bg-white px-3 py-2 text-sm focus:border-teal-600 focus:outline-none focus:ring-2 focus:ring-teal-600/30">
                <option value="">Semua assessment</option>
                <option value="1" @selected(request('assessment') === '1')>Assessment 1</option>
                <option value="2" @selected(request('assessment') === '2')>Assessment 2</option>
                <option value="manual" @selected(request('assessment') === 'manual')>Diatur admin</option>
            </select>
            <button type="submit" class="rounded border border-slate-300 bg-white px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50">Terapkan</button>
            @if (request()->hasAny(['q', 'department', 'assessment']))
                <a href="{{ route('employees.index') }}" class="px-2 py-2 text-sm text-slate-500 hover:underline">Reset filter</a>
            @endif
        </form>
        <div class="flex flex-col gap-2 sm:flex-row">
            <form method="POST" action="{{ route('employees.refresh-cache') }}" title="Pakai setelah mengubah tabel karyawan langsung di Supabase">
                @csrf
                <button type="submit" class="w-full rounded border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">Muat ulang data</button>
            </form>
            <a href="{{ route('employees.import') }}" class="rounded border border-slate-300 bg-white px-4 py-2 text-center text-sm font-semibold text-slate-700 hover:bg-slate-50">Impor dari file</a>
            <button type="button" @click="openCreate()" class="rounded bg-teal-600 px-4 py-2 text-sm font-semibold text-white hover:bg-teal-700 focus:outline-none focus:ring-2 focus:ring-teal-600 focus:ring-offset-2">
                Tambah karyawan
            </button>
        </div>
    </div>

    {{-- Table --}}
    <div class="overflow-hidden rounded-lg border border-slate-200 bg-white">
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-slate-200 text-sm">
                <thead class="bg-slate-50 text-left text-slate-600">
                    <tr>
                        <th class="px-4 py-3 font-medium">NIK</th>
                        <th class="px-4 py-3 font-medium">Nama</th>
                        <th class="px-4 py-3 font-medium">Departemen</th>
                        <th class="px-4 py-3 font-medium">Seksi</th>
                        <th class="px-4 py-3 font-medium">Grade</th>
                        <th class="px-4 py-3 font-medium">Jabatan</th>
                        <th class="px-4 py-3 font-medium">Status</th>
                        <th class="px-4 py-3 font-medium">Assessment</th>
                        <th class="px-4 py-3 text-right font-medium">Aksi</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse ($employees as $employee)
                        <tr>
                            <td class="px-4 py-3 text-slate-500">{{ $employee->id_employee }}</td>
                            <td class="px-4 py-3 font-medium text-slate-900">{{ $employee->name }}</td>
                            <td class="px-4 py-3">{{ $employee->department }}</td>
                            <td class="px-4 py-3 text-slate-600">{{ $employee->section ?: '-' }}</td>
                            <td class="px-4 py-3">{{ $employee->position }}</td>
                            <td class="px-4 py-3">{{ $employee->grade }}</td>
                            <td class="px-4 py-3">
                                <span class="inline-flex rounded px-2 py-0.5 text-xs font-medium ring-1 ring-inset {{ $employee->status === 'Active' ? 'bg-emerald-50 text-emerald-800 ring-emerald-300' : 'bg-slate-100 text-slate-600 ring-slate-300' }}">
                                    {{ $employee->status === 'Active' ? 'Aktif' : $employee->status }}
                                </span>
                            </td>
                            @php
                                $level = \App\Support\ReviewRules::levelOf($employee);
                                $auto  = \App\Support\ReviewRules::autoLevelOf($employee);
                            @endphp
                            <td class="whitespace-nowrap px-4 py-3">
                                <form method="POST" action="{{ route('employees.assessment-level', $employee) }}" class="flex items-center gap-2">
                                    @csrf
                                    @method('PATCH')
                                    <span class="inline-flex rounded px-2 py-0.5 text-xs font-semibold ring-1 ring-inset {{ $level === 2 ? 'bg-amber-50 text-amber-800 ring-amber-300' : ($level === 1 ? 'bg-teal-50 text-teal-800 ring-teal-300' : 'bg-slate-100 text-slate-500 ring-slate-300') }}">
                                        {{ $level ? "A{$level}" : '-' }}
                                    </span>
                                    <select name="assessment_level" onchange="this.form.submit()" aria-label="Assessment untuk {{ $employee->name }}"
                                            class="rounded border border-slate-300 bg-white py-1 pl-2 pr-7 text-xs focus:border-teal-600 focus:outline-none focus:ring-2 focus:ring-teal-600/30">
                                        <option value="auto" @selected($employee->assessment_level === null)>Otomatis{{ $auto ? " (A{$auto})" : '' }}</option>
                                        <option value="1" @selected((int) $employee->assessment_level === 1)>Assessment 1</option>
                                        <option value="2" @selected((int) $employee->assessment_level === 2)>Assessment 2</option>
                                    </select>
                                </form>
                            </td>
                            <td class="whitespace-nowrap px-4 py-3 text-right">
                                <button type="button"
                                        @click="openEdit(@js($employee->only(['id_employee', 'name', 'department', 'section', 'position', 'grade', 'status', 'assessment_level'])))"
                                        class="font-medium text-teal-700 hover:underline">Edit</button>
                                <form method="POST" action="{{ route('employees.destroy', $employee) }}" class="ml-3 inline"
                                      onsubmit="return confirm('Hapus {{ addslashes($employee->name) }}? Tindakan ini tidak bisa dibatalkan.')">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="font-medium text-rose-700 hover:underline">Hapus</button>
                                </form>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="9" class="px-4 py-10 text-center text-slate-500">Tidak ada karyawan. Tambahkan dengan tombol di atas.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if ($employees->hasPages())
            <div class="border-t border-slate-200 px-4 py-3">{{ $employees->links() }}</div>
        @endif
    </div>

    {{-- Create / edit modal --}}
    <div x-show="open" x-cloak class="fixed inset-0 z-50 flex items-end justify-center sm:items-center" role="dialog" aria-modal="true" @keydown.escape.window="open = false">
        <div class="absolute inset-0 bg-slate-900/60" @click="open = false"></div>

        <div class="relative max-h-[90vh] w-full overflow-y-auto rounded-t-lg bg-white shadow-xl sm:max-w-2xl sm:rounded-lg"
             x-transition:enter="transition ease-out duration-150" x-transition:enter-start="opacity-0 translate-y-2" x-transition:enter-end="opacity-100 translate-y-0">
            <form method="POST" :action="action">
                @csrf
                <input type="hidden" name="_method" value="PUT" :disabled="!isEdit">
                <input type="hidden" name="_mode" :value="isEdit ? 'edit' : 'create'">

                <div class="flex items-center justify-between border-b border-slate-200 px-5 py-4">
                    <h2 class="text-lg font-semibold text-slate-900" x-text="isEdit ? 'Edit karyawan' : 'Tambah karyawan'"></h2>
                    <button type="button" @click="open = false" class="rounded p-1 text-slate-400 hover:text-slate-600" aria-label="Tutup">
                        <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" d="M6 6l12 12M18 6L6 18"/></svg>
                    </button>
                </div>

                @if ($errors->any())
                    <div class="mx-5 mt-4 rounded border border-rose-300 bg-rose-50 px-4 py-3 text-sm text-rose-800">
                        <ul class="list-inside list-disc">
                            @foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach
                        </ul>
                    </div>
                @endif

                <div class="grid grid-cols-1 gap-4 px-5 py-5 sm:grid-cols-2">
                    <div>
                        <label class="block text-sm font-medium text-slate-700" for="f_id">NIK</label>
                        <input id="f_id" name="id_employee" type="text" x-model="form.id_employee" :readonly="isEdit" required placeholder="mis. 2024001"
                               class="mt-1 block w-full rounded border border-slate-300 px-3 py-2 text-sm read-only:bg-slate-100 focus:border-teal-600 focus:outline-none focus:ring-2 focus:ring-teal-600/30">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-slate-700" for="f_name">Nama lengkap</label>
                        <input id="f_name" name="name" type="text" x-model="form.name" required
                               class="mt-1 block w-full rounded border border-slate-300 px-3 py-2 text-sm focus:border-teal-600 focus:outline-none focus:ring-2 focus:ring-teal-600/30">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-slate-700" for="f_department">Departemen</label>
                        <select id="f_department" name="department" x-model="form.department" required
                                class="mt-1 block w-full rounded border border-slate-300 bg-white px-3 py-2 text-sm focus:border-teal-600 focus:outline-none focus:ring-2 focus:ring-teal-600/30">
                            <option value="">Pilih departemen</option>
                            @foreach ($departments as $department)<option value="{{ $department }}">{{ $department }}</option>@endforeach
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-slate-700" for="f_section">Seksi</label>
                        <input id="f_section" name="section" type="text" list="section-options" x-model="form.section" maxlength="100" placeholder="mis. IQC, PQC, TOTAL CHECK"
                               class="mt-1 block w-full rounded border border-slate-300 px-3 py-2 text-sm focus:border-teal-600 focus:outline-none focus:ring-2 focus:ring-teal-600/30">
                        <datalist id="section-options">
                            @foreach ($sections as $section)<option value="{{ $section }}">@endforeach
                        </datalist>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-slate-700" for="f_position">Grade</label>
                        <select id="f_position" name="position" x-model="form.position" required
                                class="mt-1 block w-full rounded border border-slate-300 bg-white px-3 py-2 text-sm focus:border-teal-600 focus:outline-none focus:ring-2 focus:ring-teal-600/30">
                            <option value="">Pilih grade</option>
                            @foreach ($positions as $position)<option value="{{ $position }}">{{ $position }}</option>@endforeach
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-slate-700" for="f_grade">Jabatan</label>
                        <select id="f_grade" name="grade" x-model="form.grade" required
                                class="mt-1 block w-full rounded border border-slate-300 bg-white px-3 py-2 text-sm focus:border-teal-600 focus:outline-none focus:ring-2 focus:ring-teal-600/30">
                            <option value="">Pilih jabatan</option>
                            @foreach ($grades as $grade)<option value="{{ $grade }}">{{ $grade }}</option>@endforeach
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-slate-700" for="f_status">Status kerja</label>
                        <select id="f_status" name="status" x-model="form.status" required
                                class="mt-1 block w-full rounded border border-slate-300 bg-white px-3 py-2 text-sm focus:border-teal-600 focus:outline-none focus:ring-2 focus:ring-teal-600/30">
                            @foreach ($statuses as $status)<option value="{{ $status }}">{{ $status }}</option>@endforeach
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-slate-700" for="f_assessment">Assessment</label>
                        <select id="f_assessment" name="assessment_level" x-model="form.assessment_level"
                                class="mt-1 block w-full rounded border border-slate-300 bg-white px-3 py-2 text-sm focus:border-teal-600 focus:outline-none focus:ring-2 focus:ring-teal-600/30">
                            <option value="">Otomatis (grade 1-{{ config('assessment.assessed_max_grade', 5) }} = A1, lebih tinggi = A2)</option>
                            <option value="1">Assessment 1</option>
                            <option value="2">Assessment 2 (approval / pimpinan)</option>
                        </select>
                    </div>
                </div>

                <div class="flex justify-end gap-2 border-t border-slate-200 bg-slate-50 px-5 py-3">
                    <button type="button" @click="open = false" class="rounded border border-slate-300 bg-white px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50">Batal</button>
                    <button type="submit" class="rounded bg-teal-600 px-5 py-2 text-sm font-semibold text-white hover:bg-teal-700"
                            x-text="isEdit ? 'Simpan perubahan' : 'Tambah karyawan'"></button>
                </div>
            </form>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
    function employeeManager(config) {
        const blank = { id_employee: '', name: '', department: '', section: '', position: '', grade: '', status: 'Active', assessment_level: '' };

        return {
            open: false,
            isEdit: false,
            form: { ...blank },

            init() {
                // Re-open the modal with the user's input when server-side validation failed.
                if (config.reopen) {
                    this.isEdit = config.reopen === 'edit';
                    this.form = { ...blank, ...config.old };
                    this.open = true;
                }
            },
            openCreate() { this.isEdit = false; this.form = { ...blank }; this.open = true; },
            openEdit(employee) {
                this.isEdit = true;
                this.form = { ...blank, ...employee, assessment_level: employee.assessment_level == null ? '' : String(employee.assessment_level) };
                this.open = true;
            },
            get action() {
                return this.isEdit
                    ? config.updateUrl.replace('__ID__', encodeURIComponent(this.form.id_employee))
                    : config.storeUrl;
            },
        };
    }
</script>
@endpush
