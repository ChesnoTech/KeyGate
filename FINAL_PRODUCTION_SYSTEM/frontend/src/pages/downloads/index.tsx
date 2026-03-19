import { useRef, useState, useMemo } from 'react'
import { useTranslation } from 'react-i18next'
import {
  Download,
  Upload,
  Trash2,
  Terminal,
  MonitorCog,
  Puzzle,
  FileText,
  Copy,
  Check,
  HardDrive,
  AlertCircle,
} from 'lucide-react'
import { AppHeader } from '@/components/layout/app-header'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Button } from '@/components/ui/button'
import { Badge } from '@/components/ui/badge'
import { Skeleton } from '@/components/ui/skeleton'
import {
  AlertDialog,
  AlertDialogAction,
  AlertDialogCancel,
  AlertDialogContent,
  AlertDialogDescription,
  AlertDialogFooter,
  AlertDialogHeader,
  AlertDialogTitle,
  AlertDialogTrigger,
} from '@/components/ui/alert-dialog'
import { useClientResources, useUploadResource, useDeleteResource } from '@/hooks/use-downloads'
import { getDownloadUrl, type ClientResource } from '@/api/downloads'
import { useAuth } from '@/hooks/use-auth'

/** Resource card definitions */
interface ResourceCardDef {
  key: string
  icon: typeof Terminal
  titleKey: string
  descKey: string
  acceptExts: string
  badgeLabel: string
  badgeVariant: 'default' | 'secondary' | 'outline'
}

const RESOURCE_CARDS: ResourceCardDef[] = [
  {
    key: 'oem_activator_cmd',
    icon: Terminal,
    titleKey: 'downloads.oem_launcher',
    descKey: 'downloads.oem_launcher_desc',
    acceptExts: '.cmd,.bat',
    badgeLabel: 'CMD',
    badgeVariant: 'default',
  },
  {
    key: 'ps7_installer',
    icon: MonitorCog,
    titleKey: 'downloads.ps7_installer',
    descKey: 'downloads.ps7_installer_desc',
    acceptExts: '.msi,.exe',
    badgeLabel: 'MSI',
    badgeVariant: 'secondary',
  },
  {
    key: 'chrome_hw_bridge',
    icon: Puzzle,
    titleKey: 'downloads.chrome_extension',
    descKey: 'downloads.chrome_extension_desc',
    acceptExts: '.zip,.crx',
    badgeLabel: 'Extension',
    badgeVariant: 'outline',
  },
]

function formatFileSize(bytes: number): string {
  if (bytes < 1024) return bytes + ' B'
  if (bytes < 1024 * 1024) return (bytes / 1024).toFixed(1) + ' KB'
  return (bytes / (1024 * 1024)).toFixed(2) + ' MB'
}

function formatDate(dateStr: string): string {
  try {
    return new Date(dateStr).toLocaleDateString(undefined, {
      year: 'numeric', month: 'short', day: 'numeric',
      hour: '2-digit', minute: '2-digit',
    })
  } catch {
    return dateStr
  }
}

/** Single resource download card */
function ResourceCard({
  def,
  resource,
  canManage,
  onUpload,
  onDelete,
  isUploading,
}: {
  def: ResourceCardDef
  resource: ClientResource | undefined
  canManage: boolean
  onUpload: (key: string, file: File) => void
  onDelete: (key: string) => void
  isUploading: boolean
}) {
  const { t } = useTranslation()
  const fileRef = useRef<HTMLInputElement>(null)
  const [copiedHash, setCopiedHash] = useState(false)
  const Icon = def.icon

  const handleFileChange = (e: React.ChangeEvent<HTMLInputElement>) => {
    const file = e.target.files?.[0]
    if (file) {
      onUpload(def.key, file)
      e.target.value = ''
    }
  }

  const copyChecksum = () => {
    if (resource?.checksum_sha256) {
      navigator.clipboard.writeText(resource.checksum_sha256)
      setCopiedHash(true)
      setTimeout(() => setCopiedHash(false), 2000)
    }
  }

  return (
    <Card>
      <CardHeader className="pb-3">
        <div className="flex items-start justify-between">
          <div className="flex items-center gap-3">
            <div className="rounded-lg bg-primary/10 p-2.5">
              <Icon className="h-6 w-6 text-primary" />
            </div>
            <div>
              <CardTitle className="text-base flex items-center gap-2">
                {t(def.titleKey)}
                <Badge variant={def.badgeVariant} className="text-[10px] px-1.5 py-0">
                  {def.badgeLabel}
                </Badge>
              </CardTitle>
              <CardDescription className="text-xs mt-1">
                {t(def.descKey)}
              </CardDescription>
            </div>
          </div>
        </div>
      </CardHeader>
      <CardContent>
        {resource ? (
          <div className="space-y-3">
            {/* File info */}
            <div className="rounded-md border bg-muted/40 p-3 space-y-2 text-sm">
              <div className="flex items-center justify-between">
                <span className="text-muted-foreground flex items-center gap-1.5">
                  <FileText className="h-3.5 w-3.5" />
                  {t('downloads.filename', 'File')}
                </span>
                <span className="font-mono text-xs">{resource.original_filename}</span>
              </div>
              <div className="flex items-center justify-between">
                <span className="text-muted-foreground flex items-center gap-1.5">
                  <HardDrive className="h-3.5 w-3.5" />
                  {t('downloads.file_size', 'Size')}
                </span>
                <span>{formatFileSize(resource.file_size)}</span>
              </div>
              <div className="flex items-center justify-between">
                <span className="text-muted-foreground">{t('downloads.checksum', 'SHA256')}</span>
                <button
                  onClick={copyChecksum}
                  className="inline-flex items-center gap-1 text-xs font-mono text-muted-foreground hover:text-foreground transition-colors"
                  title={resource.checksum_sha256}
                >
                  {resource.checksum_sha256.slice(0, 12)}...
                  {copiedHash ? <Check className="h-3 w-3 text-green-500" /> : <Copy className="h-3 w-3" />}
                </button>
              </div>
              <div className="flex items-center justify-between text-xs text-muted-foreground">
                <span>{t('downloads.uploaded_at', 'Uploaded')}</span>
                <span>
                  {formatDate(resource.created_at)}
                  {resource.uploaded_by_name ? ` by ${resource.uploaded_by_name}` : ''}
                </span>
              </div>
            </div>

            {/* Action buttons */}
            <div className="flex gap-2">
              <Button
                className="flex-1"
                onClick={() => {
                  const a = document.createElement('a')
                  a.href = getDownloadUrl(def.key)
                  a.download = ''
                  document.body.appendChild(a)
                  a.click()
                  a.remove()
                }}
              >
                <Download className="mr-2 h-4 w-4" />
                {t('downloads.download', 'Download')}
              </Button>

              {canManage && (
                <>
                  <Button
                    variant="outline"
                    size="icon"
                    onClick={() => fileRef.current?.click()}
                    disabled={isUploading}
                    title={t('downloads.replace', 'Replace')}
                  >
                    <Upload className="h-4 w-4" />
                  </Button>

                  <AlertDialog>
                    <AlertDialogTrigger className="inline-flex items-center justify-center rounded-lg border border-border bg-background hover:bg-muted size-8 cursor-pointer" title={t('downloads.delete', 'Delete')}>
                      <Trash2 className="h-4 w-4 text-destructive" />
                    </AlertDialogTrigger>
                    <AlertDialogContent>
                      <AlertDialogHeader>
                        <AlertDialogTitle>{t('downloads.confirm_delete_title', 'Delete Resource')}</AlertDialogTitle>
                        <AlertDialogDescription>
                          {t('downloads.confirm_delete', 'Are you sure you want to delete this resource? It will no longer be available for download.')}
                        </AlertDialogDescription>
                      </AlertDialogHeader>
                      <AlertDialogFooter>
                        <AlertDialogCancel>{t('common.cancel', 'Cancel')}</AlertDialogCancel>
                        <AlertDialogAction
                          className="bg-destructive text-destructive-foreground hover:bg-destructive/90"
                          onClick={() => onDelete(def.key)}
                        >
                          {t('downloads.delete', 'Delete')}
                        </AlertDialogAction>
                      </AlertDialogFooter>
                    </AlertDialogContent>
                  </AlertDialog>
                </>
              )}
            </div>
          </div>
        ) : (
          /* Not uploaded yet */
          <div className="space-y-3">
            <div className="rounded-md border border-dashed bg-muted/20 p-6 text-center">
              <AlertCircle className="h-8 w-8 text-muted-foreground/50 mx-auto mb-2" />
              <p className="text-sm text-muted-foreground">
                {t('downloads.not_uploaded', 'Not yet uploaded')}
              </p>
              <p className="text-xs text-muted-foreground/70 mt-1">
                {t('downloads.upload_prompt', 'Upload this resource to make it available for download')}
              </p>
            </div>

            {canManage && (
              <Button
                variant="outline"
                className="w-full"
                onClick={() => fileRef.current?.click()}
                disabled={isUploading}
              >
                <Upload className="mr-2 h-4 w-4" />
                {isUploading
                  ? t('downloads.uploading', 'Uploading...')
                  : t('downloads.upload', 'Upload')}
              </Button>
            )}
          </div>
        )}

        {/* Hidden file input */}
        <input
          ref={fileRef}
          type="file"
          accept={def.acceptExts}
          className="hidden"
          onChange={handleFileChange}
        />
      </CardContent>
    </Card>
  )
}

export function DownloadsPage() {
  const { t } = useTranslation()
  const { hasPermission } = useAuth()
  const { data, isLoading } = useClientResources()
  const uploadMutation = useUploadResource()
  const deleteMutation = useDeleteResource()

  const canManage = hasPermission('manage_downloads')

  // Build a map: resource_key -> resource
  const resourceMap = useMemo(() => {
    const map: Record<string, ClientResource> = {}
    for (const r of data?.resources ?? []) {
      map[r.resource_key] = r
    }
    return map
  }, [data])

  const handleUpload = (key: string, file: File) => {
    uploadMutation.mutate({ resourceKey: key, file })
  }

  const handleDelete = (key: string) => {
    deleteMutation.mutate(key)
  }

  return (
    <>
      <AppHeader title={t('downloads.title', 'Client Downloads')} />
      <div className="flex-1 space-y-4 p-4 md:p-6">
        <div className="flex items-center justify-between">
          <div>
            <h2 className="text-2xl font-bold tracking-tight">{t('downloads.title', 'Client Downloads')}</h2>
            <p className="text-muted-foreground text-sm mt-1">
              {t('downloads.description', 'Download tools and extensions for technician workstations')}
            </p>
          </div>
        </div>

        {isLoading ? (
          <div className="grid gap-4 md:grid-cols-2 lg:grid-cols-3">
            {[1, 2, 3].map((i) => (
              <Card key={i}>
                <CardHeader>
                  <Skeleton className="h-6 w-48" />
                  <Skeleton className="h-4 w-64 mt-2" />
                </CardHeader>
                <CardContent>
                  <Skeleton className="h-32 w-full" />
                </CardContent>
              </Card>
            ))}
          </div>
        ) : (
          <div className="grid gap-4 md:grid-cols-2 lg:grid-cols-3">
            {RESOURCE_CARDS.map((def) => (
              <ResourceCard
                key={def.key}
                def={def}
                resource={resourceMap[def.key]}
                canManage={canManage}
                onUpload={handleUpload}
                onDelete={handleDelete}
                isUploading={uploadMutation.isPending}
              />
            ))}
          </div>
        )}
      </div>
    </>
  )
}
