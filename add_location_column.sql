ALTER TABLE workers ADD COLUMN IF NOT EXISTS location geometry(Point, 4326);
CREATE INDEX IF NOT EXISTS workers_location_spatial ON workers USING GIST(location);
