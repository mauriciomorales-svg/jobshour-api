-- ============================================================================
-- QUERIES PARA DASHBOARD MEJORADO - JOBSHOUR
-- ============================================================================
-- Fecha: 17 Febrero 2026
-- Propósito: Métricas de Fidelización, Valor y Ganancias
-- ============================================================================

-- ============================================================================
-- SECCIÓN 1: SALUD DEL SISTEMA
-- ============================================================================

-- 1.1 Conteo de workers por estado
SELECT 
    COUNT(*) FILTER (WHERE availability_status = 'active') as workers_active,
    COUNT(*) FILTER (WHERE availability_status = 'intermediate') as workers_intermediate,
    COUNT(*) FILTER (WHERE availability_status = 'inactive') as workers_inactive,
    COUNT(*) as workers_total
FROM workers;

-- 1.2 Solicitudes por estado
SELECT 
    COUNT(*) FILTER (WHERE status = 'pending') as requests_pending,
    COUNT(*) FILTER (WHERE status = 'accepted') as requests_accepted,
    COUNT(*) FILTER (WHERE status = 'in_progress') as requests_in_progress,
    COUNT(*) FILTER (WHERE status = 'completed') as requests_completed,
    COUNT(*) FILTER (WHERE status = 'cancelled') as requests_cancelled,
    COUNT(*) as requests_total
FROM service_requests;

-- 1.3 Tiempo promedio de respuesta (solicitud → aceptación)
SELECT 
    ROUND(AVG(EXTRACT(EPOCH FROM (updated_at - created_at)) / 60)::numeric, 2) as avg_response_minutes
FROM service_requests
WHERE status IN ('accepted', 'in_progress', 'completed')
AND updated_at > created_at;

-- 1.4 Tasa de conversión (solicitudes → completados)
SELECT 
    COUNT(*) as total_requests,
    COUNT(*) FILTER (WHERE status = 'completed') as completed_requests,
    ROUND(100.0 * COUNT(*) FILTER (WHERE status = 'completed') / NULLIF(COUNT(*), 0), 2) as conversion_rate_pct
FROM service_requests;

-- ============================================================================
-- SECCIÓN 2: MÉTRICAS DE VALOR
-- ============================================================================

-- 2.1 GMV (Gross Merchandise Value) - Total transaccionado
SELECT 
    COALESCE(SUM(final_price), 0) as total_gmv,
    COUNT(*) FILTER (WHERE status = 'completed') as completed_jobs,
    ROUND(AVG(final_price)::numeric, 0) as avg_job_value
FROM service_requests
WHERE status = 'completed';

-- 2.2 Ingresos de plataforma (comisión 15%)
SELECT 
    COALESCE(SUM(final_price * 0.15), 0) as platform_revenue,
    COALESCE(SUM(final_price), 0) as total_gmv,
    COUNT(*) as transactions
FROM service_requests
WHERE status = 'completed';

-- 2.3 Valor por categoría
SELECT 
    c.name as category_name,
    c.icon as category_icon,
    COUNT(sr.id) as jobs_count,
    COALESCE(ROUND(AVG(sr.final_price)::numeric, 0), 0) as avg_price,
    COALESCE(SUM(sr.final_price), 0) as total_value
FROM categories c
LEFT JOIN service_requests sr ON c.id = sr.category_id AND sr.status = 'completed'
GROUP BY c.id, c.name, c.icon
ORDER BY total_value DESC;

-- 2.4 Trabajos completados hoy y esta semana
SELECT 
    COUNT(*) FILTER (WHERE created_at >= CURRENT_DATE) as jobs_today,
    COUNT(*) FILTER (WHERE created_at >= CURRENT_DATE - INTERVAL '7 days') as jobs_this_week,
    COUNT(*) FILTER (WHERE created_at >= CURRENT_DATE - INTERVAL '30 days') as jobs_this_month
FROM service_requests
WHERE status = 'completed';

-- ============================================================================
-- SECCIÓN 3: FIDELIZACIÓN
-- ============================================================================

-- 3.1 Retención de workers (activos en última semana)
SELECT 
    COUNT(DISTINCT id) as total_workers,
    COUNT(DISTINCT id) FILTER (WHERE last_seen_at >= NOW() - INTERVAL '7 days') as active_last_week,
    ROUND(100.0 * COUNT(DISTINCT id) FILTER (WHERE last_seen_at >= NOW() - INTERVAL '7 days') / NULLIF(COUNT(DISTINCT id), 0), 2) as retention_rate_pct
FROM workers;

-- 3.2 Nuevos usuarios hoy y esta semana
SELECT 
    COUNT(*) FILTER (WHERE created_at >= CURRENT_DATE) as new_users_today,
    COUNT(*) FILTER (WHERE created_at >= CURRENT_DATE - INTERVAL '7 days') as new_users_this_week,
    COUNT(*) FILTER (WHERE created_at >= CURRENT_DATE - INTERVAL '30 days') as new_users_this_month
FROM users;

-- 3.3 Workers Power Users (>5 trabajos completados)
SELECT 
    COUNT(*) as power_users_count,
    ROUND(100.0 * COUNT(*) / NULLIF((SELECT COUNT(*) FROM workers), 0), 2) as power_users_pct
FROM workers
WHERE total_jobs_completed >= 5;

-- 3.4 Clientes recurrentes (>1 solicitud)
WITH client_requests AS (
    SELECT 
        client_id,
        COUNT(*) as request_count
    FROM service_requests
    GROUP BY client_id
)
SELECT 
    COUNT(*) as total_clients,
    COUNT(*) FILTER (WHERE request_count > 1) as recurring_clients,
    ROUND(100.0 * COUNT(*) FILTER (WHERE request_count > 1) / NULLIF(COUNT(*), 0), 2) as recurring_rate_pct
FROM client_requests;

-- 3.5 Tiempo promedio en plataforma (workers)
SELECT 
    ROUND(AVG(EXTRACT(EPOCH FROM (NOW() - created_at)) / 86400)::numeric, 1) as avg_days_on_platform
FROM workers;

-- ============================================================================
-- SECCIÓN 4: OFERTA VS DEMANDA
-- ============================================================================

-- 4.1 Ratio oferta/demanda por categoría
SELECT 
    c.name as category_name,
    c.icon as category_icon,
    COUNT(DISTINCT wc.worker_id) as workers_count,
    COUNT(DISTINCT sr.id) as requests_count,
    ROUND(COUNT(DISTINCT sr.id)::numeric / NULLIF(COUNT(DISTINCT wc.worker_id), 0), 2) as demand_supply_ratio,
    CASE 
        WHEN COUNT(DISTINCT sr.id)::numeric / NULLIF(COUNT(DISTINCT wc.worker_id), 0) > 1.5 THEN 'Alta demanda'
        WHEN COUNT(DISTINCT sr.id)::numeric / NULLIF(COUNT(DISTINCT wc.worker_id), 0) < 0.5 THEN 'Baja demanda'
        ELSE 'Equilibrado'
    END as status
FROM categories c
LEFT JOIN worker_categories wc ON c.id = wc.category_id
LEFT JOIN service_requests sr ON c.id = sr.category_id
GROUP BY c.id, c.name, c.icon
ORDER BY demand_supply_ratio DESC NULLS LAST;

-- 4.2 Categorías con mayor actividad
SELECT 
    c.name as category_name,
    c.icon as category_icon,
    COUNT(DISTINCT wc.worker_id) as active_workers,
    c.active_count as workers_online_now
FROM categories c
LEFT JOIN worker_categories wc ON c.id = wc.category_id
GROUP BY c.id, c.name, c.icon, c.active_count
ORDER BY c.active_count DESC;

-- ============================================================================
-- SECCIÓN 5: PERFORMANCE DEL SISTEMA
-- ============================================================================

-- 5.1 Tasa de rechazo/cancelación
SELECT 
    COUNT(*) as total_requests,
    COUNT(*) FILTER (WHERE status = 'cancelled') as cancelled_requests,
    ROUND(100.0 * COUNT(*) FILTER (WHERE status = 'cancelled') / NULLIF(COUNT(*), 0), 2) as cancellation_rate_pct
FROM service_requests;

-- 5.2 Tiempo promedio hasta match (solicitud → aceptación)
SELECT 
    ROUND(AVG(EXTRACT(EPOCH FROM (updated_at - created_at)) / 60)::numeric, 2) as avg_match_minutes,
    MIN(EXTRACT(EPOCH FROM (updated_at - created_at)) / 60) as fastest_match_minutes,
    MAX(EXTRACT(EPOCH FROM (updated_at - created_at)) / 60) as slowest_match_minutes
FROM service_requests
WHERE status IN ('accepted', 'completed')
AND updated_at > created_at;

-- 5.3 Workers con Modo Viaje activo
SELECT 
    COUNT(*) as workers_with_active_route,
    ROUND(100.0 * COUNT(*) / NULLIF((SELECT COUNT(*) FROM workers), 0), 2) as pct_using_travel_mode
FROM workers
WHERE active_route IS NOT NULL;

-- ============================================================================
-- SECCIÓN 6: TOP PERFORMERS
-- ============================================================================

-- 6.1 Top 10 Workers por trabajos completados
SELECT 
    u.name as worker_name,
    w.total_jobs_completed,
    ROUND(w.rating::numeric, 1) as rating,
    w.rating_count,
    c.name as primary_category
FROM workers w
JOIN users u ON w.user_id = u.id
LEFT JOIN categories c ON w.category_id = c.id
ORDER BY w.total_jobs_completed DESC
LIMIT 10;

-- 6.2 Top 10 Workers por rating (mínimo 5 trabajos)
SELECT 
    u.name as worker_name,
    ROUND(w.rating::numeric, 1) as rating,
    w.rating_count,
    w.total_jobs_completed,
    c.name as primary_category
FROM workers w
JOIN users u ON w.user_id = u.id
LEFT JOIN categories c ON w.category_id = c.id
WHERE w.total_jobs_completed >= 5
ORDER BY w.rating DESC, w.rating_count DESC
LIMIT 10;

-- 6.3 Top 10 Workers por ingresos generados (cuando se active monetización)
SELECT 
    u.name as worker_name,
    COALESCE(SUM(sr.final_price), 0) as total_earned,
    COUNT(sr.id) as jobs_completed,
    ROUND(AVG(sr.final_price)::numeric, 0) as avg_job_value
FROM workers w
JOIN users u ON w.user_id = u.id
LEFT JOIN service_requests sr ON w.id = sr.worker_id AND sr.status = 'completed'
GROUP BY w.id, u.name
ORDER BY total_earned DESC
LIMIT 10;

-- ============================================================================
-- SECCIÓN 7: ANÁLISIS GEOGRÁFICO
-- ============================================================================

-- 7.1 Distribución de workers por zona (aproximada por ciudad)
WITH worker_locations AS (
    SELECT 
        id,
        ST_Y(location::geometry) as lat,
        ST_X(location::geometry) as lng
    FROM workers
    WHERE location IS NOT NULL
)
SELECT 
    CASE 
        WHEN lat BETWEEN -37.7 AND -37.6 AND lng BETWEEN -72.6 AND -72.5 THEN 'Renaico'
        WHEN lat BETWEEN -37.85 AND -37.75 AND lng BETWEEN -72.75 AND -72.65 THEN 'Angol'
        WHEN lat BETWEEN -38.0 AND -37.9 AND lng BETWEEN -72.5 AND -72.4 THEN 'Collipulli'
        ELSE 'Otra zona'
    END as zone,
    COUNT(*) as workers_count
FROM worker_locations
GROUP BY zone
ORDER BY workers_count DESC;

-- 7.2 Densidad de solicitudes por zona
WITH request_locations AS (
    SELECT 
        id,
        ST_Y(client_location::geometry) as lat,
        ST_X(client_location::geometry) as lng
    FROM service_requests
    WHERE client_location IS NOT NULL
)
SELECT 
    CASE 
        WHEN lat BETWEEN -37.7 AND -37.6 AND lng BETWEEN -72.6 AND -72.5 THEN 'Renaico'
        WHEN lat BETWEEN -37.85 AND -37.75 AND lng BETWEEN -72.75 AND -72.65 THEN 'Angol'
        WHEN lat BETWEEN -38.0 AND -37.9 AND lng BETWEEN -72.5 AND -72.4 THEN 'Collipulli'
        ELSE 'Otra zona'
    END as zone,
    COUNT(*) as requests_count
FROM request_locations
GROUP BY zone
ORDER BY requests_count DESC;

-- ============================================================================
-- SECCIÓN 8: TENDENCIAS TEMPORALES
-- ============================================================================

-- 8.1 Solicitudes por día (últimos 30 días)
SELECT 
    DATE(created_at) as date,
    COUNT(*) as requests_count,
    COUNT(*) FILTER (WHERE status = 'completed') as completed_count
FROM service_requests
WHERE created_at >= CURRENT_DATE - INTERVAL '30 days'
GROUP BY DATE(created_at)
ORDER BY date DESC;

-- 8.2 Nuevos usuarios por día (últimos 30 días)
SELECT 
    DATE(created_at) as date,
    COUNT(*) as new_users_count
FROM users
WHERE created_at >= CURRENT_DATE - INTERVAL '30 days'
GROUP BY DATE(created_at)
ORDER BY date DESC;

-- 8.3 Workers activos por día (últimos 30 días)
SELECT 
    DATE(last_seen_at) as date,
    COUNT(DISTINCT id) as active_workers_count
FROM workers
WHERE last_seen_at >= CURRENT_DATE - INTERVAL '30 days'
GROUP BY DATE(last_seen_at)
ORDER BY date DESC;

-- ============================================================================
-- SECCIÓN 9: MÉTRICAS DE EXPERIENCIA DE USUARIO
-- ============================================================================

-- 9.1 Distribución de ratings
SELECT 
    stars,
    COUNT(*) as review_count,
    ROUND(100.0 * COUNT(*) / NULLIF((SELECT COUNT(*) FROM reviews), 0), 2) as percentage
FROM reviews
GROUP BY stars
ORDER BY stars DESC;

-- 9.2 Promedio de vistas de perfil por worker
SELECT 
    ROUND(AVG(view_count)::numeric, 1) as avg_profile_views
FROM (
    SELECT worker_id, COUNT(*) as view_count
    FROM profile_views
    GROUP BY worker_id
) subquery;

-- 9.3 Workers con video CV vs sin video
SELECT 
    COUNT(*) FILTER (WHERE EXISTS (SELECT 1 FROM videos v WHERE v.worker_id = w.id AND v.type = 'vc')) as workers_with_video,
    COUNT(*) FILTER (WHERE NOT EXISTS (SELECT 1 FROM videos v WHERE v.worker_id = w.id AND v.type = 'vc')) as workers_without_video,
    ROUND(100.0 * COUNT(*) FILTER (WHERE EXISTS (SELECT 1 FROM videos v WHERE v.worker_id = w.id AND v.type = 'vc')) / NULLIF(COUNT(*), 0), 2) as video_adoption_pct
FROM workers w;

-- ============================================================================
-- SECCIÓN 10: QUERY CONSOLIDADA PARA DASHBOARD PRINCIPAL
-- ============================================================================

-- 10.1 Snapshot completo del sistema (una sola query)
WITH 
workers_stats AS (
    SELECT 
        COUNT(*) as total_workers,
        COUNT(*) FILTER (WHERE availability_status = 'active') as active_workers,
        COUNT(*) FILTER (WHERE availability_status = 'intermediate') as intermediate_workers,
        COUNT(*) FILTER (WHERE availability_status = 'inactive') as inactive_workers,
        COUNT(*) FILTER (WHERE active_route IS NOT NULL) as workers_with_travel_mode,
        COUNT(*) FILTER (WHERE total_jobs_completed >= 5) as power_users
    FROM workers
),
requests_stats AS (
    SELECT 
        COUNT(*) as total_requests,
        COUNT(*) FILTER (WHERE status = 'pending') as pending_requests,
        COUNT(*) FILTER (WHERE status = 'completed') as completed_requests,
        COUNT(*) FILTER (WHERE created_at >= CURRENT_DATE) as requests_today,
        ROUND(AVG(EXTRACT(EPOCH FROM (updated_at - created_at)) / 60) FILTER (WHERE status IN ('accepted', 'completed')), 2) as avg_response_minutes,
        COALESCE(SUM(final_price) FILTER (WHERE status = 'completed'), 0) as total_gmv
    FROM service_requests
),
users_stats AS (
    SELECT 
        COUNT(*) as total_users,
        COUNT(*) FILTER (WHERE created_at >= CURRENT_DATE) as new_users_today,
        COUNT(*) FILTER (WHERE created_at >= CURRENT_DATE - INTERVAL '7 days') as new_users_week
    FROM users
)
SELECT 
    -- Workers
    ws.total_workers,
    ws.active_workers,
    ws.intermediate_workers,
    ws.inactive_workers,
    ws.workers_with_travel_mode,
    ws.power_users,
    ROUND(100.0 * ws.active_workers / NULLIF(ws.total_workers, 0), 2) as active_workers_pct,
    
    -- Requests
    rs.total_requests,
    rs.pending_requests,
    rs.completed_requests,
    rs.requests_today,
    rs.avg_response_minutes,
    ROUND(100.0 * rs.completed_requests / NULLIF(rs.total_requests, 0), 2) as conversion_rate_pct,
    
    -- Value
    rs.total_gmv,
    ROUND(rs.total_gmv * 0.15, 0) as platform_revenue,
    
    -- Users
    us.total_users,
    us.new_users_today,
    us.new_users_week
FROM workers_stats ws, requests_stats rs, users_stats us;

-- ============================================================================
-- FIN DEL ARCHIVO
-- ============================================================================
