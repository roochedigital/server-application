<?php

namespace App\Reports;

use App\Contracts\AppReport;
use App\Helpers\ReportHelper;
use Carbon\Carbon;
use Carbon\CarbonInterval;
use Exception;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\Exportable;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class DashboardExport implements FromCollection, WithMapping, ShouldAutoSize, WithHeadings, WithStyles, AppReport
{
    use Exportable;

    public function __construct(
        private ?array $users,
        private ?array $projects,
        private Carbon $startAt,
        private Carbon $endAt
    ) {
    }

    public function collection(): Collection
    {
        return $this->queryReport()->map(static function ($el) {
            $start = Carbon::make($el->start_at);

            $el->duration = Carbon::make($el->end_at)?->diffInSeconds($start);
            $el->from_midnight = $start?->diffInSeconds($start?->copy()->startOfDay());

            return $el;
        })->groupBy('user_id');
    }

    /**
     * @param $row
     * @return array
     * @throws Exception
     */
    public function map($row): array
    {
        return array_merge(
            $row['users']
                ->map(static fn($collection) => $collection['tasks'])->flatten(1)
                ->map(static fn($collection) => array_merge(
                    $collection['intervals']->map(
                        static fn($collection) => $collection['items']
                    )->flatten(2)->map(
                        static fn($collection) => array_values($collection->only([
                            'project_name',
                            'user_name',
                            'task_name'
                        ]))
                    )->flatten(1)->all(),
                    [
                        CarbonInterval::seconds($collection['time'])->cascade()->forHumans(),
                        round(CarbonInterval::seconds($collection['time'])->totalHours, 3)
                    ]
                ))
                ->all(),
            [
                [
                    'Subtotal for ' . $row['name'],
                    '',
                    '',
                    CarbonInterval::seconds($row['time'])->cascade()->forHumans(),
                    round(CarbonInterval::seconds($row['time'])->totalHours, 3),
                ],
                []
            ]
        );
    }

    private function queryReport(): Collection
    {
        return ReportHelper::getBaseQuery(
            $this->users,
            $this->startAt,
            $this->endAt,
            [
                'time_intervals.start_at',
                'time_intervals.activity_fill',
                'time_intervals.mouse_fill',
                'time_intervals.keyboard_fill',
                'time_intervals.end_at',
                'time_intervals.is_manual',
                'users.email as user_email',
            ]
        )->whereIn('project_id', $this->projects)->get();
    }

    public function headings(): array
    {
        return [
            'User Name',
            'Hours',
            'Hours (decimal)',
        ];
    }

    public function styles(Worksheet $sheet): array
    {
        return [
            1 => ['font' => ['bold' => true], 'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER]],
        ];
    }

    public function getReportId(): string
    {
        return 'dashboard_report';
    }

    public function getLocalizedReportName(): string
    {
        return __('Dashboard Report');
    }
}
