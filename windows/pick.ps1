# 弹出选择框：-Mode folder 选文件夹，-Mode zip 选备份文件。把选中的路径输出到标准输出
param([string]$Mode = 'folder', [string]$Title = '请选择')
Add-Type -AssemblyName System.Windows.Forms
[Console]::OutputEncoding = [Text.Encoding]::UTF8
if ($Mode -eq 'zip') {
    $d = New-Object System.Windows.Forms.OpenFileDialog
    $d.Title = $Title
    $d.Filter = '备份文件 (*.zip)|*.zip'
    if ($d.ShowDialog() -eq 'OK') { Write-Output $d.FileName }
} else {
    $d = New-Object System.Windows.Forms.FolderBrowserDialog
    $d.Description = $Title
    $d.ShowNewFolderButton = $false
    if ($d.ShowDialog() -eq 'OK') { Write-Output $d.SelectedPath }
}
