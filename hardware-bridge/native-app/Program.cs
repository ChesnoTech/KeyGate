using System;
using System.IO;
using System.Linq;
using System.Text;
using System.Management;
using Newtonsoft.Json;
using Newtonsoft.Json.Linq;

namespace OEMHardwareBridge
{
    class Program
    {
        private const string VERSION = "1.0.0";

        static void Main(string[] args)
        {
            try
            {
                // Open stdin/stdout ONCE outside the loop to avoid disposing
                // the standard streams on each iteration. Disposing stdin would
                // close it permanently and break subsequent message reads.
                var stdin = Console.OpenStandardInput();

                // Native Messaging uses stdin/stdout for communication
                while (true)
                {
                    // Read message length (4 bytes, little-endian)
                    byte[] lengthBytes = new byte[4];
                    int bytesRead = stdin.Read(lengthBytes, 0, 4);
                    if (bytesRead == 0) break; // EOF

                    int messageLength = BitConverter.ToInt32(lengthBytes, 0);
                    if (messageLength <= 0 || messageLength > 1024 * 1024) break;

                    // Read message content — ensure we read exactly messageLength bytes
                    byte[] messageBytes = new byte[messageLength];
                    int totalRead = 0;
                    while (totalRead < messageLength)
                    {
                        int read = stdin.Read(messageBytes, totalRead, messageLength - totalRead);
                        if (read == 0) break; // EOF
                        totalRead += read;
                    }
                    if (totalRead < messageLength) break; // Incomplete message

                    string messageText = Encoding.UTF8.GetString(messageBytes);
                    var request = JObject.Parse(messageText);

                    // Process command
                    var response = ProcessCommand(request);

                    // Send response
                    SendMessage(response);
                }
            }
            catch (Exception ex)
            {
                // Ensure log directory exists
                string logDir = Path.Combine(
                    Environment.GetFolderPath(Environment.SpecialFolder.ApplicationData),
                    "OEMHardwareBridge");
                Directory.CreateDirectory(logDir);

                // Log errors to file for debugging
                File.AppendAllText(
                    Path.Combine(logDir, "error.log"),
                    $"{DateTime.Now}: {ex.Message}\n{ex.StackTrace}\n\n"
                );
            }
        }

        static JObject ProcessCommand(JObject request)
        {
            var command = request["command"]?.ToString();

            switch (command)
            {
                case "ping":
                    return new JObject
                    {
                        ["status"] = "ok",
                        ["version"] = VERSION,
                        ["timestamp"] = DateTime.Now.ToString("o")
                    };

                case "getUSBDevices":
                    return GetUSBDevices();

                default:
                    return new JObject
                    {
                        ["error"] = "Unknown command",
                        ["command"] = command
                    };
            }
        }

        static JObject GetUSBDevices()
        {
            var devices = new JArray();

            try
            {
                // Query USB disk drives using WMI
                var query = new SelectQuery("SELECT * FROM Win32_DiskDrive WHERE InterfaceType = 'USB'");
                using (var searcher = new ManagementObjectSearcher(query))
                {
                    foreach (ManagementObject drive in searcher.Get())
                    {
                        // Extract serial number from PNPDeviceID (most reliable source)
                        // Format: USBSTOR\DISK&VEN_xxx&PROD_xxx&REV_xxx\SERIAL&0
                        var pnpId = drive["PNPDeviceID"]?.ToString() ?? "";
                        var serialNumber = "Unknown";

                        if (!string.IsNullOrEmpty(pnpId))
                        {
                            // Get the last segment after the last backslash
                            var lastSlash = pnpId.LastIndexOf('\\');
                            if (lastSlash >= 0 && lastSlash < pnpId.Length - 1)
                            {
                                var pnpSerial = pnpId.Substring(lastSlash + 1);
                                // Remove trailing &0 or &1 suffix that Windows adds
                                var ampIdx = pnpSerial.LastIndexOf('&');
                                if (ampIdx > 0)
                                {
                                    pnpSerial = pnpSerial.Substring(0, ampIdx);
                                }
                                serialNumber = pnpSerial.Trim();
                            }
                        }

                        // Fallback to WMI SerialNumber if PNPDeviceID extraction failed
                        if (serialNumber == "Unknown" || string.IsNullOrEmpty(serialNumber))
                        {
                            var rawSerial = drive["SerialNumber"]?.ToString() ?? "Unknown";
                            serialNumber = new string(rawSerial.Where(c => !char.IsControl(c) && c != '\0').ToArray()).Trim();
                            if (string.IsNullOrEmpty(serialNumber)) serialNumber = "Unknown";
                        }

                        var device = new JObject
                        {
                            ["serialNumber"] = serialNumber,
                            ["model"] = drive["Model"]?.ToString()?.Trim() ?? "Unknown Device",
                            ["manufacturer"] = drive["Manufacturer"]?.ToString()?.Trim() ?? "Unknown",
                            ["caption"] = drive["Caption"]?.ToString()?.Trim() ?? "",
                            ["size"] = drive["Size"]?.ToString() ?? "0",
                            ["interfaceType"] = "USB",
                            ["deviceType"] = "DiskDrive"
                        };

                        devices.Add(device);
                    }
                }

                // Also get USB controllers for additional info
                var usbQuery = new SelectQuery("SELECT * FROM Win32_USBControllerDevice");
                using (var usbSearcher = new ManagementObjectSearcher(usbQuery))
                {
                    int usbCount = 0;
                    foreach (ManagementObject usb in usbSearcher.Get())
                    {
                        usbCount++;
                        if (usbCount > 20) break; // Limit to prevent excessive data
                    }
                }

                return new JObject
                {
                    ["status"] = "success",
                    ["devices"] = devices,
                    ["count"] = devices.Count,
                    ["timestamp"] = DateTime.Now.ToString("o")
                };
            }
            catch (Exception ex)
            {
                return new JObject
                {
                    ["status"] = "error",
                    ["error"] = ex.Message,
                    ["devices"] = new JArray()
                };
            }
        }

        static void SendMessage(JObject message)
        {
            string messageText = message.ToString(Formatting.None);
            byte[] messageBytes = Encoding.UTF8.GetBytes(messageText);
            byte[] lengthBytes = BitConverter.GetBytes(messageBytes.Length);

            using (var stdout = Console.OpenStandardOutput())
            {
                stdout.Write(lengthBytes, 0, 4);
                stdout.Write(messageBytes, 0, messageBytes.Length);
                stdout.Flush();
            }
        }
    }
}
