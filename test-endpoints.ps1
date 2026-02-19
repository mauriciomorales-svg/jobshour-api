# Script para probar endpoints despues del seeder
Write-Host "Probando endpoints..." -ForegroundColor Cyan
Write-Host ""

$baseUrl = "http://localhost:8095"

# 1. Probar categorias
Write-Host "1. Probando GET /api/v1/categories..." -ForegroundColor Yellow
try {
    $response = Invoke-RestMethod -Uri "$baseUrl/api/v1/categories" -Method Get
    Write-Host "   OK Categorias: $($response.Count) encontradas" -ForegroundColor Green
} catch {
    Write-Host "   Error: $($_.Exception.Message)" -ForegroundColor Red
}

# 2. Probar feed del dashboard
Write-Host ""
Write-Host "2. Probando GET /api/v1/dashboard/feed..." -ForegroundColor Yellow
try {
    $params = @{
        lat = -37.6672
        lng = -72.5730
        cursor = 0
    }
    $queryString = ($params.GetEnumerator() | ForEach-Object { "$($_.Key)=$($_.Value)" }) -join '&'
    $response = Invoke-RestMethod -Uri "$baseUrl/api/v1/dashboard/feed?$queryString" -Method Get
    Write-Host "   OK Feed: $($response.data.Count) solicitudes encontradas" -ForegroundColor Green
    if ($response.data.Count -gt 0) {
        Write-Host "   Tipos encontrados:" -ForegroundColor Cyan
        $response.data | Group-Object type | ForEach-Object {
            Write-Host "      - $($_.Name): $($_.Count)" -ForegroundColor Gray
        }
    }
} catch {
    Write-Host "   Error: $($_.Exception.Message)" -ForegroundColor Red
}

# 3. Probar demandas cercanas
Write-Host ""
Write-Host "3. Probando GET /api/v1/demand/nearby..." -ForegroundColor Yellow
try {
    $params = @{
        lat = -37.6672
        lng = -72.5730
        radius = 50
    }
    $queryString = ($params.GetEnumerator() | ForEach-Object { "$($_.Key)=$($_.Value)" }) -join '&'
    $response = Invoke-RestMethod -Uri "$baseUrl/api/v1/demand/nearby?$queryString" -Method Get
    Write-Host "   OK Demandas: $($response.data.Count) encontradas" -ForegroundColor Green
} catch {
    Write-Host "   Error: $($_.Exception.Message)" -ForegroundColor Red
}

# 4. Probar experts cercanos
Write-Host ""
Write-Host "4. Probando GET /api/v1/experts/nearby..." -ForegroundColor Yellow
try {
    $params = @{
        lat = -37.6672
        lng = -72.5730
        radius = 50
    }
    $queryString = ($params.GetEnumerator() | ForEach-Object { "$($_.Key)=$($_.Value)" }) -join '&'
    $response = Invoke-RestMethod -Uri "$baseUrl/api/v1/experts/nearby?$queryString" -Method Get
    Write-Host "   OK Experts: $($response.data.Count) encontrados" -ForegroundColor Green
    if ($response.data.Count -gt 0) {
        Write-Host "   Workers encontrados:" -ForegroundColor Cyan
        $response.data | Select-Object -First 3 | ForEach-Object {
            Write-Host "      - $($_.name) ($($_.category_name))" -ForegroundColor Gray
        }
    }
} catch {
    Write-Host "   Error: $($_.Exception.Message)" -ForegroundColor Red
}

# 5. Probar detalle de demanda
Write-Host ""
Write-Host "5. Probando detalle de demanda..." -ForegroundColor Yellow
try {
    $feedResponse = Invoke-RestMethod -Uri "$baseUrl/api/v1/dashboard/feed?lat=-37.6672&lng=-72.5730&cursor=0" -Method Get
    if ($feedResponse.data.Count -gt 0) {
        $firstRequest = $feedResponse.data[0]
        $demandResponse = Invoke-RestMethod -Uri "$baseUrl/api/v1/demand/$($firstRequest.id)" -Method Get
        Write-Host "   OK Detalle de demanda obtenido: $($demandResponse.data.description)" -ForegroundColor Green
    } else {
        Write-Host "   Advertencia: No hay solicitudes para probar detalle" -ForegroundColor Yellow
    }
} catch {
    Write-Host "   Error: $($_.Exception.Message)" -ForegroundColor Red
}

Write-Host ""
Write-Host "Pruebas completadas!" -ForegroundColor Green
Write-Host ""
