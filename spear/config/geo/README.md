# Local IP-geo database

`geo_lookup.php` reads a MaxMind-format `.mmdb` here (checked in this order):
`dbip-country-lite.mmdb`, `dbip-city-lite.mmdb`, `GeoLite2-City.mmdb`, `GeoLite2-Country.mmdb`.

The `.mmdb` is **not committed** (binary; gitignored). Refresh monthly (free, no account):

    MON=$(date +%Y-%m)
    curl -sO "https://download.db-ip.com/free/dbip-country-lite-${MON}.mmdb.gz"
    gunzip -f "dbip-country-lite-${MON}.mmdb.gz"
    mv "dbip-country-lite-${MON}.mmdb" dbip-country-lite.mmdb

City-level (country+city+coords, ~150MB uncompressed): swap `country` → `city` above.
Reader library is bundled at spear/libs/maxmind-db-reader/ (no composer needed at runtime).
