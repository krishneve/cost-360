$pwds = @('chanchal', 'Chanchal@123', 'chanchal123', 'chanchal30', 'Chanchal', 'krish', 'krishneve', 'cost360', 'cost360_dev', 'postgres123', 'admin123', '1234', '12345', '123456', '12345678', 'password123', 'root123', 'root', 'admin', 'password')
foreach ($p in $pwds) {
    $env:PGPASSWORD = $p
    $res = & "C:\Program Files\PostgreSQL\18\bin\psql.exe" -h 127.0.0.1 -U postgres -d postgres -c "ALTER USER cost360_dev CREATEDB;" 2>&1
    if ($LASTEXITCODE -eq 0) {
        Write-Host "FOUND PASSWORD: $p"
        Write-Host $res
        break
    }
}
