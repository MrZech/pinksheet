$ErrorActionPreference = 'Stop'

. "$PSScriptRoot/alert.config.ps1"

function Get-Cred {
    if ($AlertConfig.PasswordSecureStringPath -and (Test-Path $AlertConfig.PasswordSecureStringPath)) {
        $secure = Get-Content $AlertConfig.PasswordSecureStringPath | ConvertTo-SecureString
        return New-Object System.Management.Automation.PSCredential($AlertConfig.Username, $secure)
    }
    if ($AlertConfig.Password) {
        $secure = ConvertTo-SecureString $AlertConfig.Password -AsPlainText -Force
        return New-Object System.Management.Automation.PSCredential($AlertConfig.Username, $secure)
    }
    throw "No credentials available."
}

$cred = Get-Cred

Send-MailMessage -SmtpServer $AlertConfig.Server `
                 -Port $AlertConfig.Port `
                 -UseSsl:$AlertConfig.UseSsl `
                 -From $AlertConfig.From `
                 -To ($AlertConfig.To -join ',') `
                 -Subject '[Pinksheet] Test backup alert' `
                 -Body 'This is a test backup alert email.' `
                 -Credential $cred

Write-Host "Test email sent."
