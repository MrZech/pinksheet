# Copy to scripts/alert.config.ps1 and fill in your SMTP settings.
# Password can be plain or a SecureString created with:
#   Read-Host -AsSecureString | ConvertFrom-SecureString | Out-File scripts/alert.password.txt
# Then set PasswordSecureStringPath to that file.

$AlertConfig = @{
    Server   = 'smtp.example.com'
    Port     = 587
    UseSsl   = $true
    From     = 'alerts@example.com'
    To       = @('you@example.com')
    Username = 'smtp-user'
    Password = '' # leave empty if using PasswordSecureStringPath
    PasswordSecureStringPath = '' # optional path to secure-string file
}
