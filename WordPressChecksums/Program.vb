Imports System
Imports System.IO
Imports System.Security.Cryptography
Imports System.Text
Imports System.Text.Json

Module Program
    Sub Main(args As String())
        If args.Length < 1 Then
            Console.WriteLine("Usage: WordPressChecksums.exe <path_to_wordpress_folder>")
            Return
        End If

        Dim inputPath As String = args(0)

        If Not Directory.Exists(inputPath) Then
            Console.WriteLine("Error: Directory does not exist: " & inputPath)
            Return
        End If

        Try
            Dim checksums As Dictionary(Of String, String) = GenerateChecksums(inputPath)
            SaveChecksums(checksums, Path.Combine(inputPath, "checksums.json"))
            Console.WriteLine("Created checksums.json with " & checksums.Count & " files")
        Catch ex As Exception
            Console.WriteLine("Error: " & ex.Message)
        End Try
    End Sub

    Function GenerateChecksums(rootPath As String) As Dictionary(Of String, String)
        Dim checksums As New Dictionary(Of String, String)()
        Dim md5 As MD5 = MD5.Create()

        For Each filePath In Directory.GetFiles(rootPath, "*", SearchOption.AllDirectories)
            If filePath.Contains("wp-content\themes") OrElse filePath.Contains("wp-content\plugins") Then
                Continue For
            End If

            Try
                Dim relativePath As String = filePath.Substring(rootPath.Length).Replace("\", "/")
                Console.WriteLine("Processing: " & relativePath)
                Using stream As FileStream = File.OpenRead(filePath)
                    Dim hashBytes As Byte() = md5.ComputeHash(stream)
                    Dim hash As String = BitConverter.ToString(hashBytes).Replace("-", "").ToLower()
                    checksums(relativePath) = hash
                End Using
            Catch ex As Exception
                Console.WriteLine("Error processing " & filePath & ": " & ex.Message)
            End Try
        Next

        Return checksums
    End Function

    Sub SaveChecksums(checksums As Dictionary(Of String, String), outputPath As String)
        Dim options As New JsonSerializerOptions With {
            .WriteIndented = True
        }
        Dim json As String = JsonSerializer.Serialize(checksums, options)
        File.WriteAllText(outputPath, json)
    End Sub
End Module