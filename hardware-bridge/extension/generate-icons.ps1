# generate-icons.ps1
# Generates PNG icon files (16x16, 48x48, 128x128) for the Chrome extension
# Uses .NET System.Drawing to create a blue rounded-rect with a white USB plug shape
#
# Usage: powershell -ExecutionPolicy Bypass -File generate-icons.ps1

Add-Type -AssemblyName System.Drawing

$scriptDir = Split-Path -Parent $MyInvocation.MyCommand.Path

function New-RoundedRectPath {
    param(
        [System.Drawing.Graphics]$g,
        [float]$x, [float]$y, [float]$w, [float]$h, [float]$r
    )
    $path = New-Object System.Drawing.Drawing2D.GraphicsPath
    $d = $r * 2
    $path.AddArc($x, $y, $d, $d, 180, 90)
    $path.AddArc($x + $w - $d, $y, $d, $d, 270, 90)
    $path.AddArc($x + $w - $d, $y + $h - $d, $d, $d, 0, 90)
    $path.AddArc($x, $y + $h - $d, $d, $d, 90, 90)
    $path.CloseFigure()
    return $path
}

function Draw-UsbIcon {
    param([int]$size)

    $bmp = New-Object System.Drawing.Bitmap($size, $size)
    $g = [System.Drawing.Graphics]::FromImage($bmp)
    $g.SmoothingMode = [System.Drawing.Drawing2D.SmoothingMode]::AntiAlias
    $g.InterpolationMode = [System.Drawing.Drawing2D.InterpolationMode]::HighQualityBicubic
    $g.PixelOffsetMode = [System.Drawing.Drawing2D.PixelOffsetMode]::HighQuality

    # Clear to transparent
    $g.Clear([System.Drawing.Color]::Transparent)

    # Draw blue rounded rectangle background
    $margin = [math]::Max(1, [int]($size * 0.06))
    $radius = [math]::Max(2, [int]($size * 0.18))
    $bgPath = New-RoundedRectPath -g $g -x $margin -y $margin `
        -w ($size - 2 * $margin) -h ($size - 2 * $margin) -r $radius
    $blueBrush = New-Object System.Drawing.SolidBrush([System.Drawing.Color]::FromArgb(255, 41, 98, 255))
    $g.FillPath($blueBrush, $bgPath)

    # Draw USB plug shape in white
    $whitePen = New-Object System.Drawing.Pen([System.Drawing.Color]::White, [math]::Max(1, $size * 0.08))
    $whitePen.StartCap = [System.Drawing.Drawing2D.LineCap]::Round
    $whitePen.EndCap = [System.Drawing.Drawing2D.LineCap]::Round
    $whitePen.LineJoin = [System.Drawing.Drawing2D.LineJoin]::Round
    $whiteBrush = New-Object System.Drawing.SolidBrush([System.Drawing.Color]::White)

    $s = $size  # shorthand

    # USB plug body - a vertical connector shape
    # Scale all coordinates relative to icon size
    $cx = $s / 2          # center x
    $bodyW = $s * 0.36    # plug body width
    $bodyH = $s * 0.28    # plug body height
    $bodyTop = $s * 0.18
    $bodyLeft = $cx - $bodyW / 2

    # Plug body rectangle (top part - the metal housing)
    $bodyRect = New-Object System.Drawing.RectangleF($bodyLeft, $bodyTop, $bodyW, $bodyH)
    $g.FillRectangle($whiteBrush, $bodyRect)

    # Two prongs at the top of the plug
    $prongW = $s * 0.06
    $prongH = $s * 0.12
    $prongSpacing = $s * 0.10
    $prongTop = $bodyTop - $prongH + ($s * 0.02)

    $prong1X = $cx - $prongSpacing - $prongW / 2
    $prong2X = $cx + $prongSpacing - $prongW / 2
    $g.FillRectangle($whiteBrush, $prong1X, $prongTop, $prongW, $prongH)
    $g.FillRectangle($whiteBrush, $prong2X, $prongTop, $prongW, $prongH)

    # Cable coming down from plug body
    $cableW = [math]::Max(1, $s * 0.08)
    $cableTop = $bodyTop + $bodyH
    $cableBottom = $s * 0.72
    $cablePen = New-Object System.Drawing.Pen([System.Drawing.Color]::White, $cableW)
    $cablePen.StartCap = [System.Drawing.Drawing2D.LineCap]::Flat
    $cablePen.EndCap = [System.Drawing.Drawing2D.LineCap]::Round
    $g.DrawLine($cablePen, $cx, $cableTop, $cx, $cableBottom)

    # USB trident symbol at the bottom (simplified)
    # Main vertical line continues down
    $tridentBottom = $s * 0.82
    $g.DrawLine($cablePen, $cx, $cableBottom, $cx, $tridentBottom)

    # Left branch (circle terminal)
    $branchLen = $s * 0.12
    $circleR = [math]::Max(1, $s * 0.04)
    $leftBranchX = $cx - $branchLen
    $g.DrawLine($cablePen, $cx, $cableBottom, $leftBranchX, $cableBottom - $branchLen * 0.5)
    $g.FillEllipse($whiteBrush,
        $leftBranchX - $circleR, ($cableBottom - $branchLen * 0.5) - $circleR,
        $circleR * 2, $circleR * 2)

    # Right branch (square terminal)
    $rightBranchX = $cx + $branchLen
    $sqSize = [math]::Max(2, $s * 0.07)
    $g.DrawLine($cablePen, $cx, $cableBottom, $rightBranchX, $cableBottom - $branchLen * 0.5)
    $g.FillRectangle($whiteBrush,
        $rightBranchX - $sqSize / 2, ($cableBottom - $branchLen * 0.5) - $sqSize / 2,
        $sqSize, $sqSize)

    # Arrow at bottom of trident
    $arrowSize = [math]::Max(2, $s * 0.06)
    $p1 = New-Object System.Drawing.PointF([float]$cx, [float]($tridentBottom + $arrowSize))
    $p2 = New-Object System.Drawing.PointF([float]($cx - $arrowSize), [float]$tridentBottom)
    $p3 = New-Object System.Drawing.PointF([float]($cx + $arrowSize), [float]$tridentBottom)
    [System.Drawing.PointF[]]$arrowPoints = @($p1, $p2, $p3)
    $g.FillPolygon($whiteBrush, $arrowPoints)

    # Cleanup drawing objects
    $whitePen.Dispose()
    $whiteBrush.Dispose()
    $blueBrush.Dispose()
    $cablePen.Dispose()
    $bgPath.Dispose()
    $g.Dispose()

    return $bmp
}

# Generate each icon size
$sizes = @(16, 48, 128)

foreach ($size in $sizes) {
    $filename = "icon$size.png"
    $filepath = Join-Path $scriptDir $filename

    Write-Host "Generating $filename ($size x $size)..."
    $bmp = Draw-UsbIcon -size $size
    $bmp.Save($filepath, [System.Drawing.Imaging.ImageFormat]::Png)
    $bmp.Dispose()
    Write-Host "  Saved: $filepath"
}

Write-Host ""
Write-Host "All icons generated successfully." -ForegroundColor Green
