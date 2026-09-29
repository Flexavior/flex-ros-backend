$ErrorActionPreference = 'Stop'
$base = 'http://127.0.0.1:8000/api/v1'

function Call-Api($Method, $Path, $Token, $Body) {
    $headers = @{ Accept = 'application/json' }
    if ($Token) { $headers['Authorization'] = "Bearer $Token" }
    $req = @{ Method = $Method; Uri = "$base$Path"; Headers = $headers; TimeoutSec = 60 }
    if ($Body) {
        $req['Body'] = ($Body | ConvertTo-Json -Depth 6)
        $req['ContentType'] = 'application/json'
    }
    try {
        $r = Invoke-RestMethod @req
        return @{ ok = $true; data = $r }
    } catch {
        $status = $_.Exception.Response.StatusCode.value__
        return @{ ok = $false; status = $status; error = $_.ErrorDetails.Message }
    }
}

Write-Host '=== 1. Unauthenticated call (expect 401) ==='
$r = Call-Api GET '/auth/me' $null $null
Write-Host ("  status={0} expected=401 -> {1}" -f $r.status, $(if ($r.status -eq 401) { 'PASS' } else { 'FAIL' }))

Write-Host '=== 2. Login as CEO ==='
$r = Call-Api POST '/auth/login' $null @{ email = 'ceo@mss.test'; password = 'password' }
if (-not $r.ok) { Write-Host ("  FAILED: {0}" -f $r.error); exit 1 }
$ceoToken = $r.data.token
Write-Host ("  token received, role={0} -> PASS" -f $r.data.user.role.code)

Write-Host '=== 3. Login as Sales ==='
$r = Call-Api POST '/auth/login' $null @{ email = 'sales@mss.test'; password = 'password' }
$salesToken = $r.data.token
Write-Host ("  sales token received -> {0}" -f $(if ($r.ok) { 'PASS' } else { 'FAIL' }))

Write-Host '=== 4. Create a lead (sales) ==='
$r = Call-Api POST '/leads' $salesToken @{
    name = 'Smoke Test Prospect'
    company = 'Smoke Co Ltd'
    email = 'smoke@example.com'
    source = 'viber'
}
$leadId = $r.data.id
Write-Host ("  lead id={0} owner={1} status={2} -> {3}" -f $leadId, $r.data.owner.name, $r.data.status, $(if ($r.ok) { 'PASS' } else { 'FAIL' }))

Write-Host '=== 5. Add to catalogue + convert lead to customer ==='
$code = 'SMK-' + (Get-Date -Format 'HHmmss')
$svc = Call-Api POST '/products-services' $ceoToken @{ code = $code; name = "Smoke Mobile App $code"; type = 'service'; price = 15000 }
if (-not $svc.ok) { Write-Host ("  catalogue create FAILED: {0}" -f $svc.error) }
$svcId = $svc.data.id
$r = Call-Api POST "/leads/$leadId/convert" $salesToken @{ product_service_ids = @($svcId) }
$clientId = $r.data.client_id
$customerId = $r.data.id
Write-Host ("  client_id={0} -> {1}" -f $clientId, $(if ($clientId -match '^CUS-\d{4}-\d{4}$') { 'PASS' } else { 'FAIL' }))

Write-Host '=== 6. Dynamic checklists instantiated for the customer ==='
$r = Call-Api GET "/customers/$customerId" $salesToken $null
$stages = @($r.data.checklists.PSObject.Properties.Name)
Write-Host ("  stages with checklists: {0} -> {1}" -f ($stages -join ', '), $(if ($stages.Count -ge 2) { 'PASS' } else { 'FAIL' }))
$firstItem = $null
foreach ($p in $r.data.checklists.PSObject.Properties) {
    if ($p.Value.items.Count -gt 0) { $firstItem = $p.Value.items[0]; break }
}

Write-Host '=== 7. Tick a checklist item (audit: done_by/done_at) ==='
if ($firstItem) {
    $itemId = $firstItem.checklist_item_id
    $r = Call-Api PUT "/customers/$customerId/checklist/$itemId" $salesToken @{ is_done = $true }
    Write-Host ("  item '{0}' is_done={1} -> {2}" -f $r.data.item.title, $r.data.is_done, $(if ($r.data.is_done) { 'PASS' } else { 'FAIL' }))

    # Verify completion % recomputes
    $after = Call-Api GET "/customers/$customerId" $salesToken $null
    $pct = @($after.data.checklists.PSObject.Properties | ForEach-Object { $_.Value.percent })
    Write-Host ("  stage completion %: {0} -> {1}" -f ($pct -join ', '), $(if (($pct | Where-Object { $_ -gt 0 }).Count -ge 1) { 'PASS' } else { 'FAIL' }))
}

Write-Host '=== 8. Non-admin blocked from settings (expect 403) ==='
$r = Call-Api PUT '/settings/checklists' $salesToken @{ contract_sign_off = @(@{ title = 'Should not work' }) }
Write-Host ("  status={0} expected=403 -> {1}" -f $r.status, $(if ($r.status -eq 403) { 'PASS' } else { 'FAIL' }))

Write-Host '=== 9. Lint: convert same lead twice (expect 422) ==='
$r = Call-Api POST "/leads/$leadId/convert" $salesToken @{ product_service_ids = @() }
Write-Host ("  status={0} expected=422 -> {1}" -f $r.status, $(if ($r.status -eq 422) { 'PASS' } else { 'FAIL' }))

Write-Host '=== 10. CEO dashboard metrics ==='
$r = Call-Api GET '/dashboard/metrics' $ceoToken $null
if ($r.ok) {
    Write-Host ("  conversion_rate={0}% total_leads={1} open={2} stale(>={3}d)={4} checklists={5}%" -f `
        $r.data.conversion_rate, $r.data.leads.total, $r.data.leads.open, $r.data.stale_tasks.threshold_days, `
        $r.data.stale_tasks.count, $r.data.checklist.percent)
    Write-Host '  PASS'
} else { Write-Host ("  FAILED: {0}" -f $r.error) }

Write-Host '=== 11. Stage catalogue (dynamic checklist config) ==='
$r = Call-Api GET '/stages' $ceoToken $null
Write-Host ("  stages: {0} -> {1}" -f (@($r.data | ForEach-Object { $_.code }) -join ', '), $(if ($r.data.Count -ge 6) { 'PASS' } else { 'FAIL' }))

Write-Host '=== 12. Integration registry (single-pane channels) ==='
$r = Call-Api GET '/marketing/channels' $ceoToken $null
Write-Host ("  channels={0}" -f $r.data.Count)

Write-Host ''
Write-Host 'SMOKE TEST COMPLETE'
