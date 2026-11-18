@php
    $headerDashboard = is_array($dashboard ?? null) ? $dashboard : [];
    $headerPeriod = is_array($headerDashboard['period'] ?? null) ? $headerDashboard['period'] : [];
    $headerFrom = request('from', $headerPeriod['from'] ?? '');
    $headerTo = request('to', $headerPeriod['to'] ?? '');
    $headerSelectedStoreId = $selectedStoreId ?? null;
    $headerSelectedChannel = $selectedChannel ?? 'b2b';
    $headerStores = $availableStores ?? [];
    $headerBaseQuery = array_filter([
        'channel' => $headerSelectedChannel,
        'store_id' => $headerSelectedStoreId,
        'from' => $headerFrom,
        'to' => $headerTo,
    ], static fn ($value): bool => $value !== null && $value !== '');
@endphp
<style id="order-operations-header-filter-style">
.order-header-controls{display:flex;align-items:center;justify-content:flex-end;gap:6px;min-width:0;white-space:nowrap}
.order-header-filter{display:flex;align-items:center;gap:6px;min-width:0;max-width:100%;overflow-x:auto;white-space:nowrap;padding:4px;border:1px solid var(--foodex-border);border-radius:12px;background:var(--foodex-surface);box-shadow:0 4px 14px rgba(15,23,42,.035)}
.order-header-filter label{display:flex;align-items:center;gap:4px;min-width:0;flex:0 0 auto}
.order-header-filter select,.order-header-filter input{height:44px!important;min-height:44px!important;border-radius:8px!important;padding-inline:7px!important;font-size:.875rem!important;background:var(--foodex-surface)!important}
.order-header-filter input[type="date"]{width:116px}
.order-header-filter select{width:128px}
.order-header-filter .foodex-filter-action,.order-header-filter .foodex-action-secondary,.order-header-new-order{height:44px!important;min-height:44px!important;padding-inline:10px!important;border-radius:8px!important;font-size:.875rem!important;line-height:1!important}
.order-header-filter .foodex-action-secondary{min-width:44px}
.order-header-new-order{white-space:nowrap}
.order-header-filter :is(input,select,button,a):focus-visible,.order-header-mobile-filter :is(summary,input,select,button,a):focus-visible{outline:2px solid var(--foodex-action-green);outline-offset:2px}
.order-header-filter-label{position:absolute!important;width:1px!important;height:1px!important;padding:0!important;margin:-1px!important;overflow:hidden!important;clip:rect(0,0,0,0)!important;white-space:nowrap!important;border:0!important}
.order-header-mobile-filter{display:none}
@media(max-width:1180px){
    .order-header-filter{display:none}
    .order-header-mobile-filter{display:block;position:relative}
    .order-header-mobile-filter>summary{list-style:none;min-height:44px;min-width:44px;display:grid;place-items:center;border:1px solid var(--foodex-border);border-radius:9px;background:var(--foodex-surface);color:var(--foodex-green-dark);cursor:pointer;font-weight:900}
    .order-header-mobile-filter>summary::-webkit-details-marker{display:none}
    .order-header-mobile-filter-panel{position:absolute;z-index:12040;top:calc(100% + 8px);inset-inline-end:0;width:min(92vw,520px);display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:8px;padding:12px;border:1px solid var(--foodex-border);border-radius:14px;background:var(--foodex-surface);box-shadow:0 16px 42px rgba(15,23,42,.16)}
    .order-header-mobile-filter-panel label{display:grid;gap:4px;font-size:.7rem;font-weight:800}
    .order-header-mobile-filter-panel select,.order-header-mobile-filter-panel input{width:100%;box-sizing:border-box;min-height:44px;font-size:1rem}
    .order-header-mobile-filter-actions{grid-column:1/-1;display:flex;gap:6px;flex-wrap:wrap}.order-header-mobile-filter-actions>button,.order-header-mobile-filter-actions>a{min-height:44px;display:inline-flex;align-items:center;justify-content:center;box-sizing:border-box;padding:8px 12px}
}
@media(max-width:767px){
    .order-header-controls{gap:4px}
    .order-header-new-order{padding-inline:8px!important}
    .order-header-mobile-filter-panel{position:fixed;top:92px;inset-inline:12px;width:auto}
}
</style>

<div class="order-header-controls" data-order-header-controls>
    <form class="order-header-filter"
          data-order-header-filter
          method="get"
          action="{{ route('admin.operations.orders.index') }}#order-tab-analytics">
        <input type="hidden" name="channel" value="{{ $headerSelectedChannel }}">
        <input type="hidden" name="action_required" value="0">
        @if(count($headerStores) > 1)
            <label>
                <span class="order-header-filter-label">{{ __('order_operations.filters.store') }}</span>
                <select name="store_id" aria-label="{{ __('order_operations.filters.store') }}">
                    <option value="">{{ __('order_operations.filters.all_authorized') }}</option>
                    @foreach($headerStores as $storeOption)
                        <option value="{{ $storeOption['id'] }}" @selected((int)$headerSelectedStoreId === (int)$storeOption['id'])>{{ $storeOption['name'] }}</option>
                    @endforeach
                </select>
            </label>
        @elseif($headerSelectedStoreId !== null)
            <input type="hidden" name="store_id" value="{{ $headerSelectedStoreId }}">
        @endif
        <label>
            <span class="order-header-filter-label">{{ __('order_operations.filters.from') }}</span>
            <input type="date" name="from" value="{{ $headerFrom }}" aria-label="{{ __('order_operations.filters.from') }}">
        </label>
        <label>
            <span class="order-header-filter-label">{{ __('order_operations.filters.to') }}</span>
            <input type="date" name="to" value="{{ $headerTo }}" aria-label="{{ __('order_operations.filters.to') }}">
        </label>
        <button class="foodex-filter-action" type="submit">{{ __('module_dashboard.filters.apply') }}</button>
        <a class="foodex-action-secondary button secondary"
           href="{{ route('admin.operations.orders.index', ['channel' => $headerSelectedChannel, 'action_required' => 0] + ($headerSelectedStoreId ? ['store_id'=>$headerSelectedStoreId] : [])) }}#order-tab-analytics"
           aria-label="{{ __('module_dashboard.filters.reset') }}"
           title="{{ __('module_dashboard.filters.reset') }}">×</a>
    </form>

    <details class="order-header-mobile-filter">
        <summary aria-label="{{ __('module_dashboard.filters.apply') }}" title="{{ __('module_dashboard.filters.apply') }}">⌕</summary>
        <form class="order-header-mobile-filter-panel" method="get" action="{{ route('admin.operations.orders.index') }}#order-tab-analytics">
            <input type="hidden" name="channel" value="{{ $headerSelectedChannel }}">
            <input type="hidden" name="action_required" value="0">
            @if(count($headerStores) > 1)
                <label>{{ __('order_operations.filters.store') }}
                    <select name="store_id">
                        <option value="">{{ __('order_operations.filters.all_authorized') }}</option>
                        @foreach($headerStores as $storeOption)
                            <option value="{{ $storeOption['id'] }}" @selected((int)$headerSelectedStoreId === (int)$storeOption['id'])>{{ $storeOption['name'] }}</option>
                        @endforeach
                    </select>
                </label>
            @elseif($headerSelectedStoreId !== null)
                <input type="hidden" name="store_id" value="{{ $headerSelectedStoreId }}">
            @endif
            <label>{{ __('order_operations.filters.from') }}<input type="date" name="from" value="{{ $headerFrom }}"></label>
            <label>{{ __('order_operations.filters.to') }}<input type="date" name="to" value="{{ $headerTo }}"></label>
            <div class="order-header-mobile-filter-actions">
                <button class="foodex-filter-action" type="submit">{{ __('module_dashboard.filters.apply') }}</button>
                <a class="foodex-action-secondary button secondary" href="{{ route('admin.operations.orders.index', ['channel' => $headerSelectedChannel, 'action_required' => 0] + ($headerSelectedStoreId ? ['store_id'=>$headerSelectedStoreId] : [])) }}#order-tab-analytics">{{ __('module_dashboard.filters.reset') }}</a>
            </div>
        </form>
    </details>

    @if(count($newOrderWizard['channels'] ?? []))
        <button type="button" class="foodex-primary order-header-new-order" data-new-order-open>+ {{ __('order_operations.new_order') }}</button>
    @endif
</div>
