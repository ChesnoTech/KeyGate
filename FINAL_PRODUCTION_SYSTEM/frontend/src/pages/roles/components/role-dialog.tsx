import { useState, useEffect, Fragment } from 'react'
import { useTranslation } from 'react-i18next'
import {
  Dialog,
  DialogContent,
  DialogHeader,
  DialogTitle,
  DialogDescription,
  DialogFooter,
} from '@/components/ui/dialog'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Textarea } from '@/components/ui/textarea'
import { Checkbox } from '@/components/ui/checkbox'
import { Badge } from '@/components/ui/badge'
import { Separator } from '@/components/ui/separator'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
} from '@/components/ui/select'
import { ChevronDown, ChevronRight } from 'lucide-react'
import { usePermissions, useRole } from '@/hooks/use-roles'
import type { RoleRow, CreateRoleData, UpdateRoleData } from '@/api/roles'

interface RoleDialogProps {
  open: boolean
  onOpenChange: (open: boolean) => void
  role: RoleRow | null
  onSubmit: (data: CreateRoleData | UpdateRoleData) => void
  isPending: boolean
}

const roleTypeLabels: Record<string, string> = {
  admin: 'Admin',
  technician: 'Technician',
}

export function RoleDialog({ open, onOpenChange, role, onSubmit, isPending }: RoleDialogProps) {
  const { t } = useTranslation()
  const { data: permData } = usePermissions()
  // Fetch full role details (with permissions array) when editing
  const { data: fullRole } = useRole(open && role ? role.id : null)

  const [form, setForm] = useState({
    role_name: '',
    display_name: '',
    description: '',
    role_type: 'technician',
    color: '#6366f1',
    permission_ids: [] as number[],
  })

  const [collapsedCats, setCollapsedCats] = useState<Set<string>>(new Set())

  useEffect(() => {
    if (role && fullRole?.role) {
      const r = fullRole.role
      setForm({
        role_name: r.role_name,
        display_name: r.display_name,
        description: r.description,
        role_type: r.role_type,
        color: r.color || '#6366f1',
        permission_ids: Array.isArray(r.permissions)
          ? r.permissions.filter((p) => p.granted).map((p) => p.id)
          : [],
      })
    } else if (!role) {
      setForm({
        role_name: '',
        display_name: '',
        description: '',
        role_type: 'technician',
        color: '#6366f1',
        permission_ids: [],
      })
    }
  }, [role, open, fullRole])

  // Reset collapsed state when dialog opens
  useEffect(() => {
    if (open) setCollapsedCats(new Set())
  }, [open])

  const handleTogglePermission = (permId: number) => {
    setForm((prev) => ({
      ...prev,
      permission_ids: prev.permission_ids.includes(permId)
        ? prev.permission_ids.filter((id) => id !== permId)
        : [...prev.permission_ids, permId],
    }))
  }

  const toggleCategory = (cat: string) => {
    setCollapsedCats((prev) => {
      const next = new Set(prev)
      if (next.has(cat)) next.delete(cat)
      else next.add(cat)
      return next
    })
  }

  const toggleAllInCategory = (catPerms: Array<{ id: number }>, checked: boolean) => {
    setForm((prev) => {
      const catIds = new Set(catPerms.map((p) => p.id))
      const filtered = prev.permission_ids.filter((id) => !catIds.has(id))
      return {
        ...prev,
        permission_ids: checked ? [...filtered, ...catPerms.map((p) => p.id)] : filtered,
      }
    })
  }

  const handleSubmit = () => {
    if (role) {
      onSubmit({ ...form, role_id: role.id } as UpdateRoleData)
    } else {
      onSubmit(form as CreateRoleData)
    }
  }

  const categories = permData?.categories ?? []

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="sm:max-w-lg max-h-[85vh] overflow-y-auto">
        <DialogHeader>
          <DialogTitle>
            {role
              ? t('roles.edit_title', 'Edit Role')
              : t('roles.create_title', 'Create Role')}
          </DialogTitle>
          <DialogDescription>
            {role
              ? t('roles.edit_desc', 'Modify the role settings and permissions.')
              : t('roles.create_desc', 'Define a new role with specific permissions.')}
          </DialogDescription>
        </DialogHeader>

        <div className="space-y-4">
          <div className="grid grid-cols-2 gap-4">
            <div className="space-y-2">
              <Label htmlFor="role_name">{t('roles.field_role_name', 'Role Name')}</Label>
              <Input
                id="role_name"
                value={form.role_name}
                onChange={(e) => setForm({ ...form, role_name: e.target.value })}
                placeholder="viewer"
              />
            </div>
            <div className="space-y-2">
              <Label htmlFor="display_name">{t('roles.field_display_name', 'Display Name')}</Label>
              <Input
                id="display_name"
                value={form.display_name}
                onChange={(e) => setForm({ ...form, display_name: e.target.value })}
                placeholder="Viewer"
              />
            </div>
          </div>

          <div className="space-y-2">
            <Label htmlFor="description">{t('roles.field_description', 'Description')}</Label>
            <Textarea
              id="description"
              value={form.description}
              onChange={(e) => setForm({ ...form, description: e.target.value })}
              placeholder={t('roles.field_description_placeholder', 'What this role is for...')}
            />
          </div>

          <div className="grid grid-cols-2 gap-4">
            <div className="space-y-2">
              <Label>{t('roles.field_role_type', 'Role Type')}</Label>
              <Select value={form.role_type} onValueChange={(v) => v && setForm({ ...form, role_type: v })}>
                <SelectTrigger className="w-full">
                  <span className="truncate">{roleTypeLabels[form.role_type] ?? form.role_type}</span>
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value="admin">{t('roles.type_admin', 'Admin')}</SelectItem>
                  <SelectItem value="technician">{t('roles.type_technician', 'Technician')}</SelectItem>
                </SelectContent>
              </Select>
            </div>
            <div className="space-y-2">
              <Label htmlFor="color">{t('roles.field_color', 'Color')}</Label>
              <div className="flex items-center gap-2">
                <div
                  className="h-8 w-8 rounded border"
                  style={{ backgroundColor: form.color }}
                />
                <Input
                  id="color"
                  value={form.color}
                  onChange={(e) => setForm({ ...form, color: e.target.value })}
                  placeholder="#6366f1"
                  className="flex-1"
                />
              </div>
            </div>
          </div>

          <Separator />

          <div className="space-y-1">
            <Label>{t('roles.field_permissions', 'Permissions')}</Label>
            <p className="text-xs text-muted-foreground mb-2">
              {form.permission_ids.length} {t('roles.selected', 'selected')}
            </p>

            {categories.map((cat) => {
              const isCollapsed = collapsedCats.has(cat.category_key)
              const perms = cat.permissions ?? []
              const selectedInCat = perms.filter((p) => form.permission_ids.includes(p.id)).length
              const allSelected = perms.length > 0 && selectedInCat === perms.length

              return (
                <Fragment key={cat.category_key}>
                  <div className="flex items-center gap-2 py-1.5 px-1 rounded hover:bg-accent/50 -mx-1">
                    <button
                      type="button"
                      className="flex items-center gap-1.5 flex-1 text-left"
                      onClick={() => toggleCategory(cat.category_key)}
                    >
                      {isCollapsed
                        ? <ChevronRight className="h-3.5 w-3.5 text-muted-foreground shrink-0" />
                        : <ChevronDown className="h-3.5 w-3.5 text-muted-foreground shrink-0" />}
                      <span className="text-sm font-medium">{cat.display_name}</span>
                      <Badge variant="outline" className="text-[10px] px-1.5 py-0 ml-1">
                        {selectedInCat}/{perms.length}
                      </Badge>
                    </button>
                    <Checkbox
                      checked={allSelected}
                      onCheckedChange={(checked) => toggleAllInCategory(perms, !!checked)}
                      aria-label={`Select all ${cat.category_key}`}
                    />
                  </div>
                  {!isCollapsed && (
                    <div className="ml-5 grid gap-1.5 pb-2">
                      {perms.map((perm) => (
                        <label
                          key={perm.id}
                          className="flex items-center gap-2 text-sm cursor-pointer py-0.5"
                        >
                          <Checkbox
                            checked={form.permission_ids.includes(perm.id)}
                            onCheckedChange={() => handleTogglePermission(perm.id)}
                          />
                          <span className="font-mono text-xs">{perm.permission_key}</span>
                          {perm.is_dangerous && (
                            <Badge variant="destructive" className="text-[10px] px-1 py-0">
                              {t('roles.dangerous', 'dangerous')}
                            </Badge>
                          )}
                          {perm.description && (
                            <span className="text-xs text-muted-foreground truncate">
                              — {perm.description}
                            </span>
                          )}
                        </label>
                      ))}
                    </div>
                  )}
                </Fragment>
              )
            })}
          </div>
        </div>

        <DialogFooter>
          <Button variant="outline" onClick={() => onOpenChange(false)}>
            {t('common.cancel', 'Cancel')}
          </Button>
          <Button
            onClick={handleSubmit}
            disabled={isPending || !form.role_name || !form.display_name}
          >
            {isPending
              ? t('common.saving', 'Saving...')
              : role
                ? t('common.save', 'Save')
                : t('common.create', 'Create')}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  )
}
