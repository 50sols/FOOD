<!doctype html>
<html lang="{{ app()->getLocale() }}" dir="{{ app()->getLocale()==='ar' ? 'rtl' : 'ltr' }}">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>{{ __('restaurant_integrations.title') }}</title>
@include('admin._brand-components')
@include('admin._settings-page-polish')
<style>
.ri-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,320px),1fr));gap:16px}.ri-kpis{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:12px}.ri-form{display:grid;gap:12px}.ri-form label{display:grid;gap:6px}.ri-checks{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:8px}.ri-list{display:grid;gap:10px}.ri-item{border:1px solid var(--foodex-border);border-radius:14px;padding:14px;display:grid;gap:10px}.ri-head{display:flex;justify-content:space-between;gap:12px;align-items:flex-start}.ri-badge{display:inline-flex;border-radius:999px;padding:4px 9px;background:rgba(127,127,127,.12);font-size:.82rem}.ri-secret{word-break:break-all;font-family:ui-monospace,monospace}.ri-filter{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:10px;align-items:end}.ri-filter label{display:grid;gap:5px}.ri-table-wrap{overflow:auto;max-width:100%}.ri-table{width:100%;border-collapse:collapse;min-width:760px}.ri-table th,.ri-table td{padding:9px;border-bottom:1px solid var(--foodex-border);text-align:start;vertical-align:top}.ri-charts{display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,280px),1fr));gap:14px}.ri-chart{border:1px solid var(--foodex-border);border-radius:14px;padding:14px}.ri-bars{display:grid;gap:8px}.ri-bar-row{display:grid;grid-template-columns:minmax(90px,1fr) 3fr auto;gap:8px;align-items:center}.ri-bar-track{height:10px;border-radius:999px;background:rgba(127,127,127,.13);overflow:hidden}.ri-bar-fill{height:100%;background:var(--foodex-green);border-radius:999px}.ri-actions{display:flex;gap:8px;flex-wrap:wrap}.ri-muted{color:var(--foodex-muted);font-size:.9rem}.ri-report-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(210px,1fr));gap:10px}.ri-report{border:1px solid var(--foodex-border);border-radius:12px;padding:12px;display:flex;justify-content:space-between;gap:8px;align-items:center}
@media(max-width:767px){.ri-checks{grid-template-columns:1fr}.ri-head{display:grid}.ri-bar-row{grid-template-columns:90px 1fr auto}.ri-actions>*{flex:1 1 auto}}
.ri-workspace-tabs{display:flex;flex-wrap:nowrap;gap:8px;overflow-x:auto;overscroll-behavior-inline:contain;max-width:100%;padding-block:8px}.ri-workspace-tabs .foodex-tab{flex:0 0 auto;white-space:nowrap;min-height:44px;display:inline-flex;align-items:center}.ri-tab-panel{min-width:0}.ri-tab-panel .ri-grid{margin-block-end:16px}.ri-form [aria-invalid="true"]{outline:2px solid var(--foodex-danger-text);outline-offset:2px}
</style>
</head>
<body>
@php
$isAr=app()->getLocale()==='ar';
$localized=static fn($ar,$en)=>trim((string)($isAr?$ar:$en))!==''?(string)($isAr?$ar:$en):(string)($isAr?$en:$ar);
$kpis=[
 ['label'=>__('restaurant_integrations.installed'),'value'=>$health['installed']],
 ['label'=>__('restaurant_integrations.enabled'),'value'=>$health['enabled']],
 ['label'=>__('restaurant_integrations.degraded'),'value'=>$health['degraded']],
 ['label'=>__('restaurant_integrations.sync_success'),'value'=>$health['sync_success']],
 ['label'=>__('restaurant_integrations.sync_failure'),'value'=>$health['sync_failure'],'status'=>['label'=>$health['sync_failure']>0?__('restaurant_integrations.degraded'):__('restaurant_integrations.active'),'state'=>$health['sync_failure']>0?'warning':'success']],
 ['label'=>__('restaurant_integrations.retry_backlog'),'value'=>$health['retry_backlog']],
 ['label'=>__('restaurant_integrations.dead_letters'),'value'=>$health['dead_letters'],'status'=>['label'=>$health['dead_letters']>0?__('restaurant_integrations.degraded'):__('restaurant_integrations.active'),'state'=>$health['dead_letters']>0?'danger':'success']],
 ['label'=>__('restaurant_integrations.active_api_clients'),'value'=>$health['active_api_clients']],
 ['label'=>__('restaurant_integrations.revoked_api_clients'),'value'=>$health['revoked_api_clients']],
 ['label'=>__('restaurant_integrations.api_requests'),'value'=>$health['api_requests']],
 ['label'=>__('restaurant_integrations.rate_limit_violations'),'value'=>$health['rate_limit_violations']],
 ['label'=>__('restaurant_integrations.last_success'),'value'=>$health['last_success_at'] ?: __('restaurant_integrations.never')],
];
$chartSets=[
 __('restaurant_integrations.connector_health_chart')=>$analytics['connector_status'],
 __('restaurant_integrations.sync_status_chart')=>$analytics['sync_status'],
 __('restaurant_integrations.failure_reason_chart')=>$analytics['failure_reasons'],
 __('restaurant_integrations.webhook_status_chart')=>$analytics['webhook_status'],
];
$providers=collect($integrations)->pluck('provider_key')->filter()->unique()->sort()->values();
// These are sibling workspaces, not steps in one submission wizard.
$workspaceTabs=[
 'overview'=>__('restaurant_integrations.dashboard_title'),
 'logs'=>__('restaurant_integrations.log_explorer'),
 'reports'=>__('restaurant_integrations.reports'),
 'connectors'=>__('restaurant_integrations.connectors'),
 'api-clients'=>__('restaurant_integrations.api_clients'),
 'webhooks'=>__('restaurant_integrations.webhooks'),
];
$tabInput=request()->query('tab','overview');
$activeTab=is_string($tabInput)&&array_key_exists($tabInput,$workspaceTabs)?$tabInput:'overview';
$failedForm=old('_integration_form');
if($errors->any()&&is_string($failedForm)&&array_key_exists($failedForm,$workspaceTabs)){
    $activeTab=$failedForm;
}elseif(session('restaurant_api_credential')){
    $activeTab='api-clients';
}elseif(session('restaurant_webhook_secret')){
    $activeTab='webhooks';
}
$workspaceQuery=$filters+(request()->boolean('support_access')?['support_access'=>1]:[]);
$oldText=static fn(string $name): string => is_scalar(old($name))?(string)old($name):'';
$canManageIntegrations=$user->is_active&&$user->hasPermission('smart_ordering.manage',(int)$site->store_id);

@endphp
<div class="foodex-admin-layout" data-foodex-mobile-web-page="restaurant-integrations">
<aside class="sidebar">@include('admin._sidebar',['navGroups'=>app(\App\Support\AdminNavigation::class)->groupsFor($user),'navContext'=>'smart_ordering','user'=>$user])</aside>
<main class="foodex-admin-main foodex-admin-page foodex-settings-surface foodex-module-overview">
@include('admin._global-header')
<div class="foodex-page-toolbar"><div><h1>{{ __('restaurant_integrations.title') }}</h1><p>{{ __('restaurant_integrations.subtitle') }}</p></div><a class="foodex-action-primary" href="{{ route('admin.smart-ordering.index',['store_id'=>$site->store_id] + (request()->boolean('support_access') ? ['support_access'=>1] : [])) }}">{{ __('restaurant_integrations.back') }}</a></div>
@include('admin._smart-ordering-workspace-nav')
@if(session('status'))<div class="foodex-alert success" role="status">{{ session('status') }}</div>@endif
@if(session('restaurant_api_credential'))<section class="panel"><strong>{{ __('restaurant_integrations.credential_once') }}</strong><div class="ri-secret">{{ session('restaurant_api_credential') }}</div></section>@endif
@if(session('restaurant_webhook_secret'))<section class="panel"><strong>{{ __('restaurant_integrations.secret_once') }}</strong><div class="ri-secret">{{ session('restaurant_webhook_secret') }}</div></section>@endif
@if($errors->any())<div class="foodex-alert error" role="alert" id="ri-validation-error" tabindex="-1">{{ $errors->first() }}</div>@endif

<nav class="foodex-tabs ri-workspace-tabs" aria-label="{{ __('restaurant_integrations.title') }}" data-integration-workspace-tabs>
@foreach($workspaceTabs as $tabKey=>$tabLabel)
<a id="ri-tab-{{ $tabKey }}" class="foodex-tab" href="{{ route('admin.smart-ordering.integrations.index',[$site,'tab'=>$tabKey]+$workspaceQuery) }}" @if($tabKey===$activeTab) aria-current="page" @endif>{{ $tabLabel }}</a>
@endforeach
</nav>
@if($activeTab==='overview')
<section id="ri-panel-overview" class="ri-tab-panel" role="tabpanel" aria-labelledby="ri-tab-overview" data-ri-active-panel>
<section class="panel">
<div class="ri-head"><div><h2>{{ __('restaurant_integrations.dashboard_title') }}</h2><p class="ri-muted">{{ __('restaurant_integrations.dashboard_subtitle') }}</p></div></div>
<div class="foodex-module-kpis ri-kpis">@foreach($kpis as $metric) @include('admin._module-dashboard-kpi',['metric'=>$metric]) @endforeach</div>
</section>

<section class="panel"><h2>{{ __('restaurant_integrations.charts') }}</h2>
<div class="ri-charts">
@foreach($chartSets as $chartTitle=>$series)
@php $chartMax=max(1,...array_values($series ?: [1])); @endphp
<div class="ri-chart foodex-module-chart" role="img" aria-label="{{ $chartTitle }}">
<h3>{{ $chartTitle }}</h3>
@if($series)
<div class="ri-bars">@foreach($series as $label=>$value)<div class="ri-bar-row"><span>{{ $label }}</span><span class="ri-bar-track" aria-hidden="true"><span class="ri-bar-fill" style="width:{{ min(100,round(((int)$value/$chartMax)*100)) }}%"></span></span><strong>{{ $value }}</strong></div>@endforeach</div>
<table data-pagination-exempt="bounded-integration-observability" class="foodex-module-chart-table" aria-label="{{ $chartTitle }}"><tbody>@foreach($series as $label=>$value)<tr><th>{{ $label }}</th><td>{{ $value }}</td></tr>@endforeach</tbody></table>
@else <p>{{ __('restaurant_integrations.no_data') }}</p> @endif
</div>
@endforeach
<div class="ri-chart foodex-module-chart" role="region" aria-label="{{ __('restaurant_integrations.api_usage_chart') }}">
<h3>{{ __('restaurant_integrations.api_usage_chart') }}</h3>
@if($apiClients)<div class="ri-table-wrap"><table data-pagination-exempt="bounded-integration-observability" class="ri-table"><thead><tr><th>{{ __('restaurant_integrations.client_name') }}</th><th>{{ __('restaurant_integrations.requests') }}</th><th>{{ __('restaurant_integrations.rate_limit_violations') }}</th></tr></thead><tbody>@foreach($apiClients as $client)<tr><td>{{ $client['name'] }}</td><td>{{ $client['request_count'] }}</td><td>{{ $client['rate_limit_violations'] }}</td></tr>@endforeach</tbody></table></div>@else<p>{{ __('restaurant_integrations.no_data') }}</p>@endif
</div>
</div></section>


</section>
@endif
@if($activeTab==='logs')
<section id="ri-panel-logs" class="ri-tab-panel" role="tabpanel" aria-labelledby="ri-tab-logs" data-ri-active-panel>
<section class="panel" id="logs"><h2>{{ __('restaurant_integrations.log_explorer') }}</h2><p class="ri-muted">{{ __('restaurant_integrations.log_explorer_help') }}</p>
<form class="ri-filter" method="get" action="{{ route('admin.smart-ordering.integrations.index',$site) }}"><input type="hidden" name="tab" value="logs">@if(request()->boolean('support_access'))<input type="hidden" name="support_access" value="1">@endif
<label>{{ __('restaurant_integrations.provider_filter') }}<select class="foodex-control" name="provider"><option value=""></option>@foreach($providers as $provider)<option value="{{ $provider }}" @selected(($filters['provider']??'')===$provider)>{{ $provider }}</option>@endforeach</select></label>
<label>{{ __('restaurant_integrations.capability_filter') }}<select class="foodex-control" name="capability"><option value=""></option>@foreach($capabilities as $capability)<option value="{{ $capability }}" @selected(($filters['capability']??'')===$capability)>{{ $capability }}</option>@endforeach</select></label>
<label>{{ __('restaurant_integrations.direction_filter') }}<select class="foodex-control" name="direction"><option value=""></option>@foreach(['inbound','outbound'] as $direction)<option value="{{ $direction }}" @selected(($filters['direction']??'')===$direction)>{{ __('restaurant_integrations.directions.'.$direction) }}</option>@endforeach</select></label>
<label>{{ __('restaurant_integrations.status_filter') }}<select class="foodex-control" name="status"><option value=""></option>@foreach(['received','processed','retry','dead_letter'] as $status)<option value="{{ $status }}" @selected(($filters['status']??'')===$status)>{{ __('restaurant_integrations.observability_statuses.'.$status) }}</option>@endforeach</select></label>
<label>{{ __('restaurant_integrations.webhook_status_filter') }}<select class="foodex-control" name="webhook_status"><option value=""></option>@foreach(['pending','retry','delivered','dead_letter'] as $status)<option value="{{ $status }}" @selected(($filters['webhook_status']??'')===$status)>{{ __('restaurant_integrations.observability_statuses.'.$status) }}</option>@endforeach</select></label>
<label>{{ __('restaurant_integrations.branch') }}<select class="foodex-control" name="branch_id"><option value=""></option>@foreach($branches as $branch)<option value="{{ $branch->id }}" @selected((string)($filters['branch_id']??'')===(string)$branch->id)>{{ $localized($branch->name_ar,$branch->name_en) }}</option>@endforeach</select></label>
<label>{{ __('restaurant_integrations.event_type_filter') }}<input class="foodex-control" name="event_type" value="{{ $filters['event_type']??'' }}"></label>
<label>{{ __('restaurant_integrations.from') }}<input class="foodex-control" type="date" name="from" value="{{ $filters['from']??'' }}"></label>
<label>{{ __('restaurant_integrations.to') }}<input class="foodex-control" type="date" name="to" value="{{ $filters['to']??'' }}"></label>
<label>{{ __('restaurant_integrations.search') }}<input class="foodex-control" name="q" value="{{ $filters['q']??'' }}"></label>
<div class="ri-actions"><button class="foodex-action-primary" type="submit">{{ __('restaurant_integrations.apply_filters') }}</button><a class="foodex-action-secondary button secondary" href="{{ route('admin.smart-ordering.integrations.index',[$site,'tab'=>'logs']+(request()->boolean('support_access')?['support_access'=>1]:[])) }}">{{ __('restaurant_integrations.reset_filters') }}</a></div>
</form>

<h3>{{ __('restaurant_integrations.event_log') }}</h3>
<div class="ri-table-wrap"><table data-pagination-exempt="bounded-integration-observability" class="ri-table"><thead><tr><th>{{ __('restaurant_integrations.provider') }}</th><th>{{ __('restaurant_integrations.event_type_filter') }}</th><th>{{ __('restaurant_integrations.external_key') }}</th><th>{{ __('restaurant_integrations.status') }}</th><th>{{ __('restaurant_integrations.attempts') }}</th><th>{{ __('restaurant_integrations.error') }}</th><th>{{ __('restaurant_integrations.occurred_at') }}</th><th>{{ __('restaurant_integrations.action') }}</th></tr></thead><tbody>
@forelse($events as $event)<tr><td>{{ $event['provider_key'] }}</td><td>{{ $event['event_type'] }}</td><td>{{ $event['external_event_key'] }}</td><td>{{ __('restaurant_integrations.observability_statuses.'.$event['status']) }}</td><td>{{ $event['attempts'] }} · {{ __('restaurant_integrations.replays') }} {{ $event['replay_count'] }}</td><td>{{ $event['last_error_code'] ?: '—' }}</td><td>{{ $event['occurred_at'] ?: $event['created_at'] }}</td><td>@if(in_array($event['status'],['retry','dead_letter'],true))@if($canManageIntegrations)<form method="post" action="{{ route('admin.smart-ordering.integrations.events.retry',[$site,$event['id']]) }}">@csrf @if(request()->boolean('support_access'))<input type="hidden" name="support_access" value="1">@endif<input type="hidden" name="_integration_form" value="logs"><button class="foodex-action-secondary button secondary" type="submit">{{ __('restaurant_integrations.retry') }}</button></form>@endif@else — @endif</td></tr>@empty<tr><td colspan="8">{{ __('restaurant_integrations.no_events') }}</td></tr>@endforelse
</tbody></table></div>

<h3>{{ __('restaurant_integrations.webhook_delivery_log') }}</h3>
<div class="ri-table-wrap"><table data-pagination-exempt="bounded-integration-observability" class="ri-table"><thead><tr><th>{{ __('restaurant_integrations.target_url') }}</th><th>{{ __('restaurant_integrations.event_type_filter') }}</th><th>{{ __('restaurant_integrations.external_key') }}</th><th>{{ __('restaurant_integrations.status') }}</th><th>{{ __('restaurant_integrations.attempts') }}</th><th>{{ __('restaurant_integrations.latency') }}</th><th>{{ __('restaurant_integrations.error') }}</th><th>{{ __('restaurant_integrations.action') }}</th></tr></thead><tbody>
@forelse($deliveries as $delivery)<tr><td>{{ $delivery['target_url'] }}</td><td>{{ $delivery['event_type'] }}</td><td>{{ $delivery['event_key'] }}</td><td>{{ __('restaurant_integrations.observability_statuses.'.$delivery['status']) }}</td><td>{{ $delivery['attempts'] }} · {{ __('restaurant_integrations.replays') }} {{ $delivery['replay_count'] }}</td><td>{{ $delivery['latency_ms']===null?'—':$delivery['latency_ms'].' ms' }}</td><td>{{ $delivery['last_error_code'] ?: '—' }}</td><td>@if(in_array($delivery['status'],['retry','dead_letter'],true))@if($canManageIntegrations)<form method="post" action="{{ route('admin.smart-ordering.integrations.deliveries.replay',[$site,$delivery['id']]) }}">@csrf @if(request()->boolean('support_access'))<input type="hidden" name="support_access" value="1">@endif<input type="hidden" name="_integration_form" value="logs"><button class="foodex-action-secondary button secondary" type="submit">{{ __('restaurant_integrations.replay') }}</button></form>@endif@else — @endif</td></tr>@empty<tr><td colspan="8">{{ __('restaurant_integrations.no_deliveries') }}</td></tr>@endforelse
</tbody></table></div>
</section>


</section>
@endif
@if($activeTab==='reports')
<section id="ri-panel-reports" class="ri-tab-panel" role="tabpanel" aria-labelledby="ri-tab-reports" data-ri-active-panel>
<section class="panel"><h2>{{ __('restaurant_integrations.reports') }}</h2><div class="ri-report-grid">
@foreach(['connector-health'=>'report_connector_health','sync-failures'=>'report_sync_failures','api-usage'=>'report_api_usage','webhook-performance'=>'report_webhook_performance','exceptions'=>'report_exceptions'] as $report=>$label)
<div class="ri-report"><span>{{ __('restaurant_integrations.'.$label) }}</span><a class="foodex-action-secondary button secondary" href="{{ route('admin.smart-ordering.integrations.export',[$site,'report'=>$report]) }}">{{ __('restaurant_integrations.download_csv') }}</a></div>
@endforeach
</div></section>


</section>
@endif
@if($activeTab==='connectors')
<section id="ri-panel-connectors" class="ri-tab-panel" role="tabpanel" aria-labelledby="ri-tab-connectors" data-ri-active-panel>
<div class="ri-grid">
<section class="panel"><h2>{{ __('restaurant_integrations.install') }}</h2>
@if($canManageIntegrations)<form class="ri-form" method="post" action="{{ route('admin.smart-ordering.integrations.install',$site) }}">@csrf @if(request()->boolean('support_access'))<input type="hidden" name="support_access" value="1">@endif<input type="hidden" name="_integration_form" value="connectors">
<label>{{ __('restaurant_integrations.branch') }}<select class="foodex-control" name="branch_id"><option value="">{{ __('restaurant_integrations.all_branches') }}</option>@foreach($branches as $branch)<option value="{{ $branch->id }}" @selected($oldText("branch_id")===(string)$branch->id)>{{ $localized($branch->name_ar,$branch->name_en) }}</option>@endforeach</select></label>
<label>{{ __('restaurant_integrations.type') }}<select class="foodex-control" name="connector_type" required>@foreach($connectorTypes as $type)<option value="{{ $type }}" @selected($oldText("connector_type")===$type)>{{ __('restaurant_integrations.connector_types.'.$type) }}</option>@endforeach</select></label>
<label>{{ __('restaurant_integrations.provider_key') }}<input class="foodex-control" name="provider_key" value="{{ $oldText("provider_key") }}" pattern="[A-Za-z0-9_-]+" @if($errors->has("provider_key")) aria-invalid="true" @endif required></label>
<label>{{ __('restaurant_integrations.display_name') }}<input class="foodex-control" name="display_name" value="{{ $oldText("display_name") }}" @if($errors->has("display_name")) aria-invalid="true" @endif required></label>
<label>{{ __('restaurant_integrations.credential_reference') }}<input class="foodex-control" name="credential_reference" value="{{ $oldText("credential_reference") }}" placeholder="{{ __('restaurant_integrations.credential_reference_placeholder') }}"><small>{{ __('restaurant_integrations.credential_reference_help') }}</small></label>
<fieldset @if($errors->has("capabilities")) aria-invalid="true" tabindex="-1" @endif><legend>{{ __('restaurant_integrations.capabilities') }}</legend><div class="ri-checks">@foreach($capabilities as $capability)<label><input type="checkbox" name="capabilities[]" value="{{ $capability }}" @checked(in_array($capability,(array)old("capabilities",[]),true))> {{ $capability }}</label>@endforeach</div></fieldset>
<button class="foodex-action-primary" type="submit">{{ __('restaurant_integrations.save_connector') }}</button>
</form>@endif</section>


</div>
<section class="panel"><h2>{{ __('restaurant_integrations.connector_details') }}</h2><div class="ri-list">
@forelse($integrations as $row)
<div class="ri-item"><div class="ri-head"><div><strong>{{ $row['display_name'] }}</strong><div>{{ $row['provider_key'] }} · {{ __('restaurant_integrations.connector_types.'.$row['connector_type']) }}</div></div><span class="ri-badge">{{ __('restaurant_integrations.status_options.'.$row['status']) }}</span></div>
<div>{{ implode(', ',$row['capabilities']) }}</div>
<div class="ri-muted">{{ __('restaurant_integrations.branch') }}: {{ ($row['branch_name_en'] || $row['branch_name_ar'])?$localized($row['branch_name_ar'],$row['branch_name_en']):__('restaurant_integrations.site_scope') }}</div>
<div class="ri-muted">{{ __('restaurant_integrations.last_success') }}: {{ $row['last_success_at'] ?: __('restaurant_integrations.never') }} · {{ __('restaurant_integrations.last_failure') }}: {{ $row['last_failure_at'] ?: __('restaurant_integrations.never') }} · {{ __('restaurant_integrations.last_error') }}: {{ $row['last_error_code'] ?: '—' }}</div>
<div class="ri-muted">{{ $row['credential_reference']?__('restaurant_integrations.credentials_configured'):__('restaurant_integrations.credentials_not_configured') }}</div>
@if($canManageIntegrations)<form method="post" action="{{ route('admin.smart-ordering.integrations.status',[$site,$row['public_id']]) }}">@csrf @if(request()->boolean('support_access'))<input type="hidden" name="support_access" value="1">@endif @method('PATCH')<input type="hidden" name="_integration_form" value="connectors"><div class="ri-head"><select class="foodex-control" name="status">@foreach(['disabled','enabled','degraded','suspended'] as $status)<option value="{{ $status }}" @selected($status===$row['status'])>{{ __('restaurant_integrations.status_options.'.$status) }}</option>@endforeach</select><button class="foodex-action-primary" type="submit">{{ __('restaurant_integrations.status') }}</button></div></form>@endif
</div>
@empty<p>{{ __('restaurant_integrations.no_connectors') }}</p>@endforelse
</div></section>


</section>
@endif
@if($activeTab==='api-clients')
<section id="ri-panel-api-clients" class="ri-tab-panel" role="tabpanel" aria-labelledby="ri-tab-api-clients" data-ri-active-panel>
<div class="ri-grid">
<section class="panel"><h2>{{ __('restaurant_integrations.api_clients') }}</h2>
@if($canManageIntegrations)<form class="ri-form" method="post" action="{{ route('admin.smart-ordering.integrations.api-clients.store',$site) }}">@csrf @if(request()->boolean('support_access'))<input type="hidden" name="support_access" value="1">@endif<input type="hidden" name="_integration_form" value="api-clients">
<label>{{ __('restaurant_integrations.client_name') }}<input class="foodex-control" name="name" value="{{ $oldText("name") }}" @if($errors->has("name")) aria-invalid="true" @endif required></label>
<label>{{ __('restaurant_integrations.expires_at') }}<input class="foodex-control" type="datetime-local" name="expires_at" value="{{ $oldText("expires_at") }}"></label>
<fieldset @if($errors->has("scopes")) aria-invalid="true" tabindex="-1" @endif><legend>{{ __('restaurant_integrations.scopes') }}</legend><div class="ri-checks">@foreach(['restaurant.read','menu.read','orders.read','integrations.write'] as $scope)<label><input type="checkbox" name="scopes[]" value="{{ $scope }}" @checked(in_array($scope,(array)old("scopes",[]),true))> {{ $scope }}</label>@endforeach</div></fieldset>
<button class="foodex-action-primary" type="submit">{{ __('restaurant_integrations.create_client') }}</button>
</form>@endif</section>


</div>
<section class="panel"><h2>{{ __('restaurant_integrations.api_clients_list') }}</h2><div class="ri-list">
@forelse($apiClients as $client)<div class="ri-item"><div class="ri-head"><div><strong>{{ $client['name'] }}</strong><div>{{ implode(', ',$client['scopes']) }}</div></div><span class="ri-badge">{{ __('restaurant_integrations.'.$client['state']) }}</span></div><div class="ri-muted">{{ __('restaurant_integrations.requests') }}: {{ $client['request_count'] }} · {{ __('restaurant_integrations.rate_limit_violations') }}: {{ $client['rate_limit_violations'] }} · {{ __('restaurant_integrations.last_used') }}: {{ $client['last_used_at'] ?: __('restaurant_integrations.never') }} · {{ __('restaurant_integrations.expires_at') }}: {{ $client['expires_at'] ?: '—' }}</div>@if($client['state']==='active')@if($canManageIntegrations)<form method="post" action="{{ route('admin.smart-ordering.integrations.api-clients.revoke',[$site,$client['public_id']]) }}">@csrf @if(request()->boolean('support_access'))<input type="hidden" name="support_access" value="1">@endif @method('PATCH')<input type="hidden" name="_integration_form" value="api-clients"><button class="foodex-action-secondary button secondary" type="submit">{{ __('restaurant_integrations.revoke') }}</button></form>@endif@endif</div>
@empty<p>{{ __('restaurant_integrations.no_data') }}</p>@endforelse
</div></section>


</section>
@endif
@if($activeTab==='webhooks')
<section id="ri-panel-webhooks" class="ri-tab-panel" role="tabpanel" aria-labelledby="ri-tab-webhooks" data-ri-active-panel>
<div class="ri-grid">
<section class="panel"><h2>{{ __('restaurant_integrations.webhooks') }}</h2>
@if($canManageIntegrations)<form class="ri-form" method="post" action="{{ route('admin.smart-ordering.integrations.webhooks.store',$site) }}">@csrf @if(request()->boolean('support_access'))<input type="hidden" name="support_access" value="1">@endif<input type="hidden" name="_integration_form" value="webhooks">
<label>{{ __('restaurant_integrations.target_url') }}<input class="foodex-control" type="url" name="target_url" value="{{ $oldText("target_url") }}" @if($errors->has("target_url")) aria-invalid="true" @endif placeholder="{{ __('restaurant_integrations.target_url_placeholder') }}" required></label>
<fieldset @if($errors->has("event_types")) aria-invalid="true" tabindex="-1" @endif><legend>{{ __('restaurant_integrations.event_types') }}</legend><small>{{ __('restaurant_integrations.event_types_help') }}</small><div class="ri-checks">@foreach(['order.created','order.status_changed','payment.updated','menu.published','*'] as $event)<label><input type="checkbox" name="event_types[]" value="{{ $event }}" @checked(in_array($event,(array)old("event_types",[]),true))> {{ $event }}</label>@endforeach</div></fieldset>
<button class="foodex-action-primary" type="submit">{{ __('restaurant_integrations.create_webhook') }}</button>
</form>@endif</section>

</div>
<section class="panel"><h2>{{ __('restaurant_integrations.webhook_subscriptions') }}</h2><div class="ri-list">
@forelse($webhooks as $hook)<div class="ri-item"><div class="ri-head"><div><strong>{{ $hook['target_url'] }}</strong><div>{{ implode(', ',$hook['event_types']) }}</div></div><span class="ri-badge">{{ $hook['is_active']?__('restaurant_integrations.active'):__('restaurant_integrations.inactive') }}</span></div>@if($canManageIntegrations)<form method="post" action="{{ route('admin.smart-ordering.integrations.webhooks.status',[$site,$hook['public_id']]) }}">@csrf @if(request()->boolean('support_access'))<input type="hidden" name="support_access" value="1">@endif @method('PATCH')<input type="hidden" name="_integration_form" value="webhooks"><input type="hidden" name="active" value="{{ $hook['is_active']?0:1 }}"><button class="foodex-action-secondary button secondary" type="submit">{{ $hook['is_active']?__('restaurant_integrations.disable'):__('restaurant_integrations.enable') }}</button></form>@endif</div>
@empty<p>{{ __('restaurant_integrations.no_webhooks') }}</p>@endforelse
</div></section>

</section>
@endif
</main></div>
<script>
(() => {
    document.querySelectorAll('[data-ri-active-panel] form[method="post"]').forEach((form) => {
        form.addEventListener('submit', (event) => {
            if (form.dataset.submitting === 'true') { event.preventDefault(); return; }
            form.dataset.submitting = 'true';
            const button = form.querySelector('button[type="submit"]');
            if (button) { button.disabled = true; button.setAttribute('aria-busy', 'true'); }
        });
    });
    const error = document.getElementById('ri-validation-error');
    if (error) {
        const field = document.querySelector('[data-ri-active-panel] [aria-invalid="true"]');
        const target = field || error;
        target.focus({ preventScroll: true });
        target.scrollIntoView({ block: 'center', behavior: 'auto' });
    }
})();
</script>
</body></html>
