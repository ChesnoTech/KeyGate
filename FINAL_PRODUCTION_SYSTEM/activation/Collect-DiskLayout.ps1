function Collect-CompleteDiskLayout {
    <#
    .SYNOPSIS
    Collects complete disk layout including all partitions (visible, hidden, system, recovery)

    .DESCRIPTION
    Gathers comprehensive disk and partition information similar to partition management tools
    Shows all partitions including EFI, Recovery, System Reserved, and hidden partitions

    .OUTPUTS
    Array of disk objects with complete partition layout
    #>

    Write-Host "  • Complete disk layout..." -ForegroundColor Gray

    $diskLayout = @()

    try {
        # Get all physical disks
        $physicalDisks = Get-WmiObject Win32_DiskDrive -ErrorAction Stop | Sort-Object Index

        foreach ($disk in $physicalDisks) {
            $diskInfo = @{
                disk_number = $disk.Index
                disk_model = $disk.Model
                disk_interface = $disk.InterfaceType
                disk_size_gb = [math]::Round($disk.Size / 1GB, 2)
                disk_serial = $disk.SerialNumber
                partition_style = $null  # Will be filled from diskpart if available
                partitions = @()
            }

            # Try to get partition style (GPT/MBR) using Get-Disk cmdlet (PowerShell 3.0+)
            try {
                if (Get-Command Get-Disk -ErrorAction SilentlyContinue) {
                    $diskObj = Get-Disk -Number $disk.Index -ErrorAction SilentlyContinue
                    if ($diskObj) {
                        $diskInfo['partition_style'] = $diskObj.PartitionStyle
                    }
                }
            } catch {
                # Cmdlet not available or error, continue without partition style
            }

            # Get all partitions on this disk using WMI associations
            $diskPartitions = Get-WmiObject -Query "ASSOCIATORS OF {Win32_DiskDrive.DeviceID='$($disk.DeviceID)'} WHERE AssocClass=Win32_DiskDriveToDiskPartition" -ErrorAction SilentlyContinue

            if ($diskPartitions) {
                foreach ($partition in $diskPartitions) {
                    $partitionInfo = @{
                        partition_number = $partition.Index
                        partition_type = $partition.Type
                        bootable = $partition.Bootable
                        primary = $partition.PrimaryPartition
                        size_gb = [math]::Round($partition.Size / 1GB, 2)
                        starting_offset_gb = [math]::Round($partition.StartingOffset / 1GB, 4)
                        block_size = $partition.BlockSize
                        drive_letter = $null
                        file_system = $null
                        volume_name = $null
                        free_space_gb = $null
                        used_space_gb = $null
                        partition_purpose = $null  # Will identify EFI, Recovery, etc.
                    }

                    # Get logical disk (volume) information if partition has a drive letter
                    $logicalDisks = Get-WmiObject -Query "ASSOCIATORS OF {Win32_DiskPartition.DeviceID='$($partition.DeviceID)'} WHERE AssocClass=Win32_LogicalDiskToPartition" -ErrorAction SilentlyContinue

                    if ($logicalDisks) {
                        $volume = $logicalDisks | Select-Object -First 1
                        $partitionInfo['drive_letter'] = $volume.DeviceID
                        $partitionInfo['file_system'] = $volume.FileSystem
                        $partitionInfo['volume_name'] = $volume.VolumeName
                        $partitionInfo['free_space_gb'] = [math]::Round($volume.FreeSpace / 1GB, 2)
                        $partitionInfo['used_space_gb'] = [math]::Round(($volume.Size - $volume.FreeSpace) / 1GB, 2)
                    }

                    # Identify partition purpose based on type, size, and position
                    $partitionInfo['partition_purpose'] = Get-PartitionPurpose -PartitionType $partition.Type `
                                                                               -SizeGB $partitionInfo['size_gb'] `
                                                                               -Bootable $partition.Bootable `
                                                                               -VolumeName $partitionInfo['volume_name'] `
                                                                               -FileSystem $partitionInfo['file_system']

                    $diskInfo['partitions'] += $partitionInfo
                }
            }

            # Sort partitions by starting offset
            $diskInfo['partitions'] = $diskInfo['partitions'] | Sort-Object { [double]$_.starting_offset_gb }

            $diskLayout += $diskInfo
        }

        return $diskLayout

    } catch {
        Write-Host "    ⚠️ Error collecting disk layout: $($_.Exception.Message)" -ForegroundColor Yellow
        return @()
    }
}

function Get-PartitionPurpose {
    param(
        [string]$PartitionType,
        [decimal]$SizeGB,
        [bool]$Bootable,
        [string]$VolumeName,
        [string]$FileSystem
    )

    # Identify partition purpose based on characteristics

    # EFI System Partition (ESP)
    if ($PartitionType -match "EFI|GPT: system") {
        return "EFI System Partition"
    }

    # Microsoft Reserved Partition (MSR)
    if ($PartitionType -match "Microsoft Reserved|GPT: Microsoft reserved") {
        return "Microsoft Reserved"
    }

    # Recovery Partition
    if ($VolumeName -match "Recovery|WINRE" -or $PartitionType -match "Recovery|GPT: recovery") {
        return "Recovery Partition"
    }

    # System Reserved (MBR style)
    if ($SizeGB -lt 1 -and $Bootable -and $FileSystem -eq "NTFS") {
        return "System Reserved"
    }

    # OEM Partition
    if ($VolumeName -match "OEM|PRELOAD" -or $PartitionType -match "OEM") {
        return "OEM Partition"
    }

    # Windows OS Partition (typically large, bootable or contains Windows)
    if ($FileSystem -eq "NTFS" -and $SizeGB -gt 10) {
        if ($VolumeName -match "Windows|OS|System") {
            return "Windows OS"
        } else {
            return "Data Partition"
        }
    }

    # Data partitions
    if ($FileSystem -in @("NTFS", "FAT32", "exFAT", "ReFS")) {
        return "Data Partition"
    }

    # Unknown/Other
    if ($PartitionType) {
        return "Unknown ($PartitionType)"
    } else {
        return "Unknown"
    }
}
