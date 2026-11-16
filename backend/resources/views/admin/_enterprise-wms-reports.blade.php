@php
    $reportLinks = [
        'wms.inventory_summary' => ['label' => __('enterprise_wms.reports.inventory_summary'), 'tab' => 'overview'],
        'wms.low_stock' => ['label' => __('enterprise_wms.reports.low_stock'), 'tab' => 'reorder'],
        'wms.stock_aging' => ['label' => __('enterprise_wms.reports.stock_aging'), 'tab' => 'aging'],
        'wms.lot_expiry' => ['label' => __('enterprise_wms.reports.lot_expiry'), 'tab' => 'lots'],
        'wms.count_variance' => ['label' => __('enterprise_wms.reports.count_variance'), 'tab' => 'counts'],
        'wms.adjustments' => ['label' => __('enterprise_wms.reports.adjustments'), 'tab' => 'adjustments'],
        'wms.transfer_performance' => ['label' => __('enterprise_wms.reports.transfer_performance'), 'tab' => 'transfers'],
        'wms.demand_plan' => ['label' => __('enterprise_wms.reports.demand_plan'), 'tab' => 'planning'],
    ];
    $reportBase = array_filter(['store_id'=>$storeId,'warehouse_id'=>$warehouseId]);
    // Selection changes display only. EnterpriseWmsController keeps sole
    // store, warehouse, inventory, entitlement and legal-scope authority.
    $reportPanelKeys = ['low_stock','stock_aging','count_variance','transfer_performance','demand_plan'];
    $reportPanelInput = request()->query('report_panel');
    $activeReportPanel = is_string($reportPanelInput)
        && in_array($reportPanelInput, $reportPanelKeys, true)
        ? $reportPanelInput
        : 'low_stock';
@endphp
<div class="foodex-module-overview" data-wms-reports>
    <div class="foodex-page-toolbar">
        <div><h2>{{ __('enterprise_wms.reports.title') }}</h2><p>{{ __('enterprise_wms.reports.subtitle') }}</p></div>
    </div>

    <div class="wms-report-cards">
        @foreach($reportLinks as $code => $report)
            <a class="foodex-viz-card wms-report-card" data-report-code="{{ $code }}" href="{{ route('admin.erp.wms.index',array_merge($reportBase,['tab'=>$report['tab']])) }}">
                <strong>{{ $report['label'] }}</strong>
                <small>{{ __('enterprise_wms.reports.open_report') }}</small>
            </a>
        @endforeach
    </div>

    <nav class="wms-tabs foodex-tabs" aria-label="{{ __('enterprise_wms.reports.title') }}" data-wms-report-tabs>
        @foreach($reportPanelKeys as $reportPanelKey)
            <a class="wms-tab foodex-tab {{ $activeReportPanel === $reportPanelKey ? 'is-active' : '' }}"
               style="min-height:44px"
               data-wms-report-tab="{{ $reportPanelKey }}"
               href="{{ route('admin.erp.wms.index', array_merge($reportBase, ['tab'=>'reports','report_panel'=>$reportPanelKey])) }}"
               @if($activeReportPanel === $reportPanelKey) aria-current="page" @endif>{{ __('enterprise_wms.reports.'.$reportPanelKey) }}</a>
        @endforeach
    </nav>

    @if($activeReportPanel === 'low_stock')
    <section class="foodex-viz-card" data-wms-report-panel="low_stock">
        <h3>{{ __('enterprise_wms.reports.low_stock') }}</h3>
        <div class="wms-table-wrap"><table class="wms-table foodex-table" data-pagination-required="server-bounded"><thead><tr><th>{{ __('enterprise_wms.common.product') }}</th><th>{{ __('enterprise_wms.common.warehouse') }}</th><th>{{ __('enterprise_wms.common.available') }}</th><th>{{ __('enterprise_wms.reorder.minimum') }}</th><th>{{ __('enterprise_wms.reorder.reorder_quantity') }}</th></tr></thead><tbody>
        @forelse($lowStockReportRows as $row)<tr><td>{{ $row->product_name }}<br><small>{{ $row->product_sku }}</small></td><td>{{ $row->warehouse_name }}</td><td>{{ \App\Support\DisplayNumber::quantity((float)$row->available_quantity) }}</td><td>{{ \App\Support\DisplayNumber::quantity((float)$row->minimum_quantity) }}</td><td>{{ \App\Support\DisplayNumber::quantity((float)$row->reorder_quantity) }}</td></tr>@empty<tr><td colspan="5" class="wms-empty">{{ __('enterprise_wms.common.none') }}</td></tr>@endforelse
        </tbody></table></div>
    </section>

    @endif
    @if($activeReportPanel === 'stock_aging')
    <section class="foodex-viz-card" data-wms-report-panel="stock_aging">
        <h3>{{ __('enterprise_wms.reports.stock_aging') }}</h3>
        <div class="wms-table-wrap"><table class="wms-table foodex-table" data-pagination-required="server-bounded"><thead><tr><th>{{ __('enterprise_wms.common.product') }}</th><th>{{ __('enterprise_wms.common.warehouse') }}</th><th>{{ __('enterprise_wms.common.quantity') }}</th><th>{{ __('enterprise_wms.aging.age_days') }}</th><th>{{ __('enterprise_wms.common.value') }}</th></tr></thead><tbody>
        @forelse($agingReportRows as $row)<tr><td>{{ $row->product_name }}<br><small>{{ $row->product_sku }}</small></td><td>{{ $row->warehouse_name }}</td><td>{{ \App\Support\DisplayNumber::quantity((float)$row->quantity) }}</td><td>{{ $row->age_days }}</td><td>{{ $row->inventory_value===null ? '—' : \App\Support\DisplayNumber::money((float)$row->inventory_value,null) }}</td></tr>@empty<tr><td colspan="5" class="wms-empty">{{ __('enterprise_wms.common.none') }}</td></tr>@endforelse
        </tbody></table></div>
    </section>

    @endif
    @if($activeReportPanel === 'count_variance')
    <section class="foodex-viz-card" data-wms-report-panel="count_variance">
        <h3>{{ __('enterprise_wms.reports.count_variance') }}</h3>
        <div class="wms-table-wrap"><table class="wms-table foodex-table" data-pagination-required="server-bounded"><thead><tr><th>{{ __('enterprise_wms.common.product') }}</th><th>{{ __('enterprise_wms.counts.expected') }}</th><th>{{ __('enterprise_wms.counts.counted') }}</th><th>{{ __('enterprise_wms.overview.dashboard.count_variance') }}</th><th>{{ __('enterprise_wms.common.created') }}</th></tr></thead><tbody>
        @forelse($countVarianceReportRows as $row)<tr><td>{{ $row->product_name }}<br><small>{{ $row->product_sku }}</small></td><td>{{ \App\Support\DisplayNumber::quantity((float)$row->expected_quantity) }}</td><td>{{ \App\Support\DisplayNumber::quantity((float)$row->counted_quantity) }}</td><td>{{ \App\Support\DisplayNumber::quantity((float)$row->variance_quantity) }}</td><td>{{ $row->counted_at ? \Illuminate\Support\Carbon::parse($row->counted_at)->format('Y-m-d H:i') : '—' }}</td></tr>@empty<tr><td colspan="5" class="wms-empty">{{ __('enterprise_wms.common.none') }}</td></tr>@endforelse
        </tbody></table></div>
    </section>

    @endif
    @if($activeReportPanel === 'transfer_performance')
    <section class="foodex-viz-card" data-wms-report-panel="transfer_performance">
        <h3>{{ __('enterprise_wms.reports.transfer_performance') }}</h3>
        <div class="wms-table-wrap"><table class="wms-table foodex-table" data-pagination-required="server-bounded"><thead><tr><th>{{ __('enterprise_wms.transfers.source') }}</th><th>{{ __('enterprise_wms.transfers.target') }}</th><th>{{ __('enterprise_wms.common.status') }}</th><th>{{ __('enterprise_wms.reports.dispatch_hours') }}</th><th>{{ __('enterprise_wms.reports.receipt_hours') }}</th></tr></thead><tbody>
        @forelse($transferPerformanceRows as $row)<tr><td>{{ $row->source_name }}</td><td>{{ $row->target_name }}</td><td><span class="foodex-status-badge" data-state="{{ $row->status }}">{{ __('enterprise_wms.transfers.statuses.'.$row->status) }}</span></td><td>{{ $row->dispatch_hours===null ? '—' : number_format((float)$row->dispatch_hours,2).' '.__('enterprise_wms.common.hours') }}</td><td>{{ $row->receipt_hours===null ? '—' : number_format((float)$row->receipt_hours,2).' '.__('enterprise_wms.common.hours') }}</td></tr>@empty<tr><td colspan="5" class="wms-empty">{{ __('enterprise_wms.common.none') }}</td></tr>@endforelse
        </tbody></table></div>
    </section>

    @endif
    @if($activeReportPanel === 'demand_plan')
    <section class="foodex-viz-card" data-wms-report-panel="demand_plan">
        <h3>{{ __('enterprise_wms.reports.demand_plan') }}</h3>
        <div class="wms-table-wrap"><table class="wms-table foodex-table" data-pagination-required="server-bounded"><thead><tr><th>{{ __('enterprise_wms.common.product') }}</th><th>{{ __('enterprise_wms.common.available') }}</th><th>{{ __('enterprise_wms.planning.velocity') }}</th><th>{{ __('enterprise_wms.planning.lead_time') }}</th><th>{{ __('enterprise_wms.planning.recommendation') }}</th></tr></thead><tbody>
        @forelse($demandRows as $row)<tr><td>{{ $row['product_name'] }}<br><small>{{ $row['sku'] }}</small></td><td>{{ \App\Support\DisplayNumber::quantity((float)$row['inputs']['available']) }}</td><td>{{ \App\Support\DisplayNumber::quantity((float)$row['inputs']['velocity_units_per_day']) }}</td><td>{{ number_format((float)$row['inputs']['lead_time_days'],2) }} {{ __('enterprise_wms.common.days') }}</td><td>{{ \App\Support\DisplayNumber::quantity((float)$row['recommended_quantity']) }}</td></tr>@empty<tr><td colspan="5" class="wms-empty">{{ __('enterprise_wms.common.none') }}</td></tr>@endforelse
        </tbody></table></div>
    </section>

    @endif

    <div class="foodex-module-state" data-foodex-module-state="current" role="status">
        <strong>{{ __('enterprise_wms.reports.large_export') }}</strong>
        <p>{{ __('enterprise_wms.reports.subtitle') }}</p>
    </div>
</div>
