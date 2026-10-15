<?php

namespace App\Http\Controllers;

use App\Models\Assessment;
use App\Models\Employee;
use App\Support\RefCache;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Admin (HRD) edits the assessment guidelines (criteria_descriptions) per department and section.
 * A row with an empty section is the guideline for the whole department (fallback).
 * Writes use the query builder so they do not depend on the model's $fillable / primary key settings.
 */
class CriteriaDescriptionController extends Controller
{
    private const TABLE = 'criteria_descriptions';

    public function index(): View
    {
        $rows = DB::table(self::TABLE)->orderBy('department')->orderBy('section')->get();

        // Special routes (computed): which Assessment 1 employees are assessed / approved by other accounts than their department's
        $map      = \App\Support\ReviewRules::routeMap();
        $accounts = \App\Models\User::whereIn('id', collect($map['routes'])->flatMap(fn ($r) => [$r['assessor'], $r['approver']])->unique())->get()->keyBy('id');
        $routes   = Employee::where('status', 'Active')->orderBy('name')->get()
            ->map(fn ($e) => ['employee' => $e, 'route' => \App\Support\ReviewRules::routeFor($e)])
            ->filter(fn ($x) => $x['route'] !== null)
            ->groupBy(fn ($x) => ($x['route']['label'] ?? $x['route']['why']) . '|' . $x['route']['assessor'] . '|' . $x['route']['approver'])
            ->map(fn ($list) => [
                'why'      => $list->first()['route']['label'] ?? $list->first()['route']['why'],
                'assessor' => $accounts->get($list->first()['route']['assessor']),
                'approver' => $accounts->get($list->first()['route']['approver']),
                'people'   => $list->pluck('employee'),
            ])->sortBy('why')->values();

        return view('criteria.index', [
            'rows'          => $rows,
            'missing'       => $this->missing($rows),
            'routes'        => $routes,
            'routeProblems' => $map['problems'],
        ]);
    }

    public function create(Request $request): View
    {
        $copy = $request->filled('copy_from')
            ? DB::table(self::TABLE)->where('id_desc', (int) $request->query('copy_from'))->first()
            : null;

        $row = (object) array_merge(
            ['id_desc' => null, 'department' => (string) $request->query('department', ''), 'section' => (string) $request->query('section', '')],
            collect(range(1, 6))->mapWithKeys(fn ($i) => ["desc{$i}" => $copy->{"desc{$i}"} ?? ''])->all()
        );

        return view('criteria.form', $this->formData($row, copied: $copy !== null));
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);

        if ($this->duplicate($data['department'], $data['section'], null)) {
            return back()->withInput()->withErrors([
                'section' => 'Panduan untuk departemen dan seksi ini sudah ada. Edit panduan tersebut.',
            ]);
        }

        DB::table(self::TABLE)->insert($data + ['created_at' => now(), 'updated_at' => now()]);

        RefCache::flush('guidelines');

        return redirect()->route('criteria.index')->with('success', 'Panduan dibuat.');
    }

    public function edit(int $id): View
    {
        return view('criteria.form', $this->formData($this->find($id)));
    }

    public function update(Request $request, int $id): RedirectResponse
    {
        $this->find($id);
        $data = $this->validated($request);

        if ($this->duplicate($data['department'], $data['section'], $id)) {
            return back()->withInput()->withErrors([
                'section' => 'Departemen dan seksi ini sudah dipakai panduan lain.',
            ]);
        }

        DB::table(self::TABLE)->where('id_desc', $id)->update($data + ['updated_at' => now()]);

        RefCache::flush('guidelines');

        return redirect()->route('criteria.index')->with('success', 'Panduan diperbarui.');
    }

    public function destroy(int $id): RedirectResponse
    {
        $row = $this->find($id);

        DB::table(self::TABLE)->where('id_desc', $id)->delete();
        RefCache::flush('guidelines');

        return redirect()->route('criteria.index')
            ->with('success', "Panduan {$row->department}" . (filled($row->section) ? " / {$row->section}" : '') . ' dihapus.');
    }

    /* ------------------------------------------------------------------ */

    private function find(int $id): object
    {
        return DB::table(self::TABLE)->where('id_desc', $id)->first() ?? abort(404);
    }

    private function validated(Request $request): array
    {
        $rules = [
            'department' => ['required', 'string', 'max:100'],
            'section'    => ['nullable', 'string', 'max:100'],
        ];

        foreach (range(1, 6) as $i) {
            $rules["desc{$i}"] = ['nullable', 'string', 'max:5000'];
        }

        $validated = $request->validate($rules);

        $data = [
            'department' => trim($validated['department']),
            'section'    => filled($validated['section'] ?? null) ? trim($validated['section']) : null,
        ];

        foreach (range(1, 6) as $i) {
            // Normalise Windows line breaks so the text looks the same everywhere
            $data["desc{$i}"] = str_replace(["\r\n", "\r"], "\n", (string) ($validated["desc{$i}"] ?? ''));
        }

        return $data;
    }

    private function duplicate(string $department, ?string $section, ?int $ignoreId): bool
    {
        return DB::table(self::TABLE)
            ->whereRaw('LOWER(TRIM(department)) = ?', [mb_strtolower(trim($department))])
            ->when(
                $section === null,
                fn ($q) => $q->where(fn ($w) => $w->whereNull('section')->orWhereRaw("TRIM(section) = ''")),
                fn ($q) => $q->whereRaw('LOWER(TRIM(section)) = ?', [mb_strtolower(trim($section))])
            )
            ->when($ignoreId, fn ($q) => $q->where('id_desc', '!=', $ignoreId))
            ->exists();
    }

    private function formData(object $row, bool $copied = false): array
    {
        $departments = collect(Employee::query()->whereNotNull('department')->distinct()->pluck('department'))
            ->merge(DB::table(self::TABLE)->distinct()->pluck('department'))
            ->map(fn ($d) => trim($d))->filter()->unique()->sort(SORT_NATURAL | SORT_FLAG_CASE)->values();

        $sections = Employee::query()->whereNotNull('section')->distinct()->pluck('section')
            ->map(fn ($s) => trim($s))->filter()->unique()->sort(SORT_NATURAL | SORT_FLAG_CASE)->values();

        return [
            'row'         => $row,
            'isEdit'      => $row->id_desc !== null,
            'copied'      => $copied,
            'departments' => $departments,
            'sections'    => $sections,
            'sources'     => DB::table(self::TABLE)->orderBy('department')->orderBy('section')->get(['id_desc', 'department', 'section']),
            'criteria'    => Assessment::CRITERIA,
        ];
    }

    /** Department / section pairs of Assessment 1 employees that no guideline row covers yet (Assessment 2 people do not need one). */
    private function missing(Collection $rows): Collection
    {
        $pairs = \App\Support\ReviewRules::assessedPairs();

        return $pairs->filter(function ($pair) use ($rows) {
            foreach ($rows as $row) {
                if (mb_strtolower(trim((string) $row->department)) !== mb_strtolower(trim((string) $pair->department))) {
                    continue;
                }

                $rowSection = trim((string) $row->section);

                if ($rowSection === '' || mb_strtolower($rowSection) === mb_strtolower(trim((string) $pair->section))) {
                    return false;
                }
            }

            return true;
        })->values();
    }
}
