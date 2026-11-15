{{-- P274: authorized read-only WMS balances, never certified physical Van custody. --}}
<section class="fieldops-card" data-van-assigned-wms-inventory aria-labelledby="van-assigned-warehouse-heading">
    <h2 id="van-assigned-warehouse-heading">{{ __('van_inventory.assigned_stock') }}</h2>
    <p class="fieldops-muted">{{ __('van_inventory.source') }}</p>
    <div class="foodex-ops-state" role="note">
        {{ __('van_inventory.provenance_warning') }}
    </div>
    <div class="control-row">
        <span>{{ __('van_inventory.warehouse') }}</span>
        <strong>{{ $vanWarehouseStock['warehouse_name'] }}</strong>
    </div>
    <div class="table-wrap">
        <table class="foodex-ops-grid" data-pagination-required="keyset-50">
            <caption>{{ __('van_inventory.inventory_table') }}</caption>
            <thead><tr>
                <th scope="col">{{ __('van_inventory.product') }}</th>
                <th scope="col">{{ __('van_inventory.sku') }}</th>
                <th scope="col">{{ __('van_inventory.quantity') }}</th>
                <th scope="col">{{ __('van_inventory.reserved') }}</th>
                <th scope="col">{{ __('van_inventory.available') }}</th>
            </tr></thead>
            <tbody>
            @forelse($vanWarehouseStock['rows'] as $row)
                <tr>
                    <td>{{ $row['product_name'] }}</td>
                    <td>{{ $row['sku'] }}</td>
                    <td>{{ number_format($row['quantity'], 3) }}</td>
                    <td>{{ number_format($row['reserved_quantity'], 3) }}</td>
                    <td>{{ number_format($row['available_quantity'], 3) }}</td>
                </tr>
            @empty
                <tr><td colspan="5">{{ __('van_inventory.empty') }}</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    <nav class="fieldops-actions" aria-label="{{ __('van_inventory.inventory_table') }}">
        @if($vanWarehouseStock['next_cursor'] !== null)
        <a class="foodex-primary" href="{{ route('admin.field-operations.vans.show', ['van' => $van->id, 'tab' => 'warehouse-stock', 'stock_after' => $vanWarehouseStock['next_cursor']]) }}" rel="next">{{ __('van_inventory.next_page') }}</a>
        @endif
        @if(request()->filled('stock_after'))
        <a href="{{ route('admin.field-operations.vans.show', ['van' => $van->id, 'tab' => 'warehouse-stock']) }}">{{ __('van_inventory.start_over') }}</a>
        @endif
    </nav>
</section>
