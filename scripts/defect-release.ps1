#Requires -Version 5.1

<#
.SYNOPSIS
    Creates a draft GitHub release that gets one thing wrong on purpose.

.DESCRIPTION
    Builds the real zip for the one package under src with
    scripts/wordpress-package.ps1, changes it according to -Defect, and
    creates a draft release for -Version with gh. The release is a draft, so
    it does not exist as far as the updater is concerned until it is
    published. Its notes say what the defect is and what the updater should
    do about it.

    The defects are:

      wrong-folder      The top-level folder in the zip has the wrong name.
      no-folder         The zip has no top-level folder.
      wrong-asset-name  The asset name does not match <slug>-*.zip.
      two-assets        Two assets match <slug>-*.zip.
      no-asset          The release has no asset.
      bad-tag           The tag has no leading v.
      pre-release-flag  A plain version is marked as a pre-release on GitHub.

    The script needs gh on the PATH, and GH_TOKEN and GH_REPO set, which the
    Defect Release workflow does. Use -DryRun to build the assets and the
    plan without gh.

.PARAMETER Defect
    The defect to build.

.PARAMETER Version
    The version for the defect release, three numbers such as 9.9.9. Use one
    that has no release or tag yet. A version higher than every real release
    makes the defect release the one the updater looks at first.

.PARAMETER SourcePath
    The directory that contains the package folder. Defaults to src at the
    repository root.

.PARAMETER OutputPath
    The directory the assets and plan.json are written to. Defaults to
    dist/defect at the repository root.

.PARAMETER DryRun
    Build the assets and write plan.json, but do not call gh.

.EXAMPLE
    ./scripts/defect-release.ps1 -Defect wrong-folder -Version 9.9.9 -DryRun

    Builds the wrong-folder zip in dist/defect/assets without creating a release.
#>
[CmdletBinding()]
param(
    [Parameter(Mandatory)]
    [ValidateSet('wrong-folder', 'no-folder', 'wrong-asset-name', 'two-assets', 'no-asset', 'bad-tag', 'pre-release-flag')]
    [string]$Defect,

    [Parameter(Mandatory)]
    [string]$Version,

    [string]$SourcePath = (Join-Path (Split-Path -Parent $PSScriptRoot) 'src'),
    [string]$OutputPath = (Join-Path (Split-Path -Parent $PSScriptRoot) 'dist/defect'),
    [switch]$DryRun
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

Add-Type -AssemblyName System.IO.Compression
Add-Type -AssemblyName System.IO.Compression.FileSystem

$Version = $Version.Trim() -replace '^[vV]', ''
if ($Version -notmatch '^\d+\.\d+\.\d+$') {
    throw "The version '$Version' must be three numbers, such as 9.9.9."
}

function Copy-Zip {
    <# Copies a zip, giving every entry the name the NewName script block returns. #>
    param(
        [Parameter(Mandatory)][string]$From,
        [Parameter(Mandatory)][string]$To,
        [Parameter(Mandatory)][scriptblock]$NewName
    )

    if (Test-Path -LiteralPath $To) {
        Remove-Item -LiteralPath $To -Force
    }

    $source = [System.IO.Compression.ZipFile]::OpenRead($From)
    try {
        $target = [System.IO.Compression.ZipFile]::Open($To, [System.IO.Compression.ZipArchiveMode]::Create)
        try {
            foreach ($entry in $source.Entries) {
                $name = & $NewName $entry.FullName
                $copy = $target.CreateEntry($name, [System.IO.Compression.CompressionLevel]::Optimal)
                $in = $entry.Open()
                $out = $copy.Open()
                try {
                    $in.CopyTo($out)
                }
                finally {
                    $out.Dispose()
                    $in.Dispose()
                }
            }
        }
        finally {
            $target.Dispose()
        }
    }
    finally {
        $source.Dispose()
    }
}

# ---- main ----

$packages = @(Get-ChildItem -LiteralPath $SourcePath -Directory)
if ($packages.Count -ne 1) {
    throw "Expected one package folder under $SourcePath but found $($packages.Count)."
}
$slug = $packages[0].Name

$null = New-Item -ItemType Directory -Path $OutputPath -Force
$OutputPath = (Resolve-Path -LiteralPath $OutputPath).Path
$buildPath = Join-Path $OutputPath 'build'
$assetPath = Join-Path $OutputPath 'assets'
foreach ($folder in @($buildPath, $assetPath)) {
    if (Test-Path -LiteralPath $folder) {
        Remove-Item -LiteralPath $folder -Recurse -Force
    }
    $null = New-Item -ItemType Directory -Path $folder
}

$packager = Join-Path $PSScriptRoot 'wordpress-package.ps1'
$built = @(& $packager -SourcePath $SourcePath -OutputPath $buildPath -Clean -PassThru | Where-Object { $_.PSObject.Properties['Package'] })
if ($built.Count -ne 1) {
    throw "Expected the packaging script to write one zip but it wrote $($built.Count)."
}
$sourceZip = $built[0].Path

$tag = "v$Version"
$prerelease = $false
$normal = "$slug-$Version.zip"
$names = @($normal)

switch ($Defect) {
    'wrong-folder' {
        Copy-Zip -From $sourceZip -To (Join-Path $assetPath $normal) -NewName { param($name) "$slug-wrong/" + $name.Substring($slug.Length + 1) }
        $expected = 'The update is offered. Installing it fails with the folder error, and nothing is installed.'
    }
    'no-folder' {
        Copy-Zip -From $sourceZip -To (Join-Path $assetPath $normal) -NewName { param($name) $name.Substring($slug.Length + 1) }
        $expected = 'The update is offered. Installing it fails with the folder error, and nothing is installed.'
    }
    'wrong-asset-name' {
        $names = @("other-$Version.zip")
        Copy-Item -LiteralPath $sourceZip -Destination (Join-Path $assetPath $names[0])
        $expected = 'No update is offered. The status line in the settings section says the newest release is not set up correctly.'
    }
    'two-assets' {
        $names = @($normal, "$slug-$Version-copy.zip")
        foreach ($name in $names) {
            Copy-Item -LiteralPath $sourceZip -Destination (Join-Path $assetPath $name)
        }
        $expected = 'No update is offered. The status line in the settings section says the newest release is not set up correctly.'
    }
    'no-asset' {
        $names = @()
        $expected = 'No update is offered. The status line in the settings section says the newest release is not set up correctly.'
    }
    'bad-tag' {
        $tag = $Version
        Copy-Item -LiteralPath $sourceZip -Destination (Join-Path $assetPath $normal)
        $expected = 'The release is ignored because its tag has no leading v. The updater offers the highest valid release, or none.'
    }
    'pre-release-flag' {
        $prerelease = $true
        Copy-Item -LiteralPath $sourceZip -Destination (Join-Path $assetPath $normal)
        $expected = 'The release is ignored because GitHub marks it as a pre-release. The updater offers the highest valid release, or none.'
    }
}

$assets = @($names | ForEach-Object { [pscustomobject]@{ name = $_; path = (Join-Path $assetPath $_) } })
$plan = [pscustomobject]@{
    defect     = $Defect
    slug       = $slug
    tag        = $tag
    prerelease = $prerelease
    assets     = $assets
    expected   = $expected
}
$plan | ConvertTo-Json -Depth 5 | Set-Content -LiteralPath (Join-Path $OutputPath 'plan.json') -Encoding UTF8

$notes = @(
    "Defect release made by the Defect Release workflow. Defect: $Defect.",
    '',
    "Expected: $expected",
    '',
    'Delete this release and its tag when the test is done.'
) -join "`n"

if ($DryRun) {
    Write-Host "Dry run. The release would be $tag, with $($names.Count) asset(s), pre-release: $prerelease."
    Write-Host "Expected: $expected"
    return
}

gh api "repos/$env:GH_REPO/git/ref/tags/$tag" --silent 2>$null
if ($LASTEXITCODE -eq 0) {
    throw "The tag $tag already exists. Choose another version."
}

$existing = @(gh api --paginate "repos/$env:GH_REPO/releases?per_page=100" --jq '.[].tag_name')
if ($LASTEXITCODE -ne 0) {
    throw 'Could not list the existing releases.'
}
if ($existing -contains $tag) {
    throw "A release for $tag already exists, possibly as a draft. Choose another version."
}

$target = if ($env:GITHUB_SHA) { $env:GITHUB_SHA } else { 'main' }
$arguments = @($tag) + @($assets | ForEach-Object { $_.path }) + @('--draft', '--title', $tag, '--notes', $notes, '--target', $target)
if ($prerelease) {
    $arguments += '--prerelease'
}

$url = gh release create @arguments
if ($LASTEXITCODE -ne 0) {
    throw "Could not create the draft release for $tag."
}

Write-Host "Created draft release $tag at $url"
if ($env:GITHUB_STEP_SUMMARY) {
    Add-Content -Path $env:GITHUB_STEP_SUMMARY -Value @("### Defect release created: $Defect", '', "Release: $url", '', "Expected: $expected", '', 'Publish it to test the updater, and delete the release and its tag afterward.')
}
