$hbaPath = "C:\Program Files\PostgreSQL\18\data\pg_hba.conf"
$origContent = Get-Content $hbaPath -Raw
$trustContent = $origContent.Replace("host    all             all             127.0.0.1/32            scram-sha-256", "host    all             all             127.0.0.1/32            trust")
Set-Content $hbaPath -Value $trustContent
& "C:\Program Files\PostgreSQL\18\bin\pg_ctl.exe" reload -D "C:\Program Files\PostgreSQL\18\data"
Start-Sleep -Seconds 1
& "C:\Program Files\PostgreSQL\18\bin\psql.exe" -h 127.0.0.1 -U postgres -d postgres -c "ALTER USER cost360_dev CREATEDB;"
Set-Content $hbaPath -Value $origContent
& "C:\Program Files\PostgreSQL\18\bin\pg_ctl.exe" reload -D "C:\Program Files\PostgreSQL\18\data"
