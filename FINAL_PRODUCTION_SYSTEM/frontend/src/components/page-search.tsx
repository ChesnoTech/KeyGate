import { Search } from 'lucide-react'
import { Input } from '@/components/ui/input'

interface PageSearchProps {
  value: string
  onChange: (value: string) => void
  placeholder?: string
  className?: string
}

/**
 * Standard search input with magnifying-glass icon.
 * Used on every table page (keys, history, logs, technicians, devices).
 */
export function PageSearch({ value, onChange, placeholder, className }: PageSearchProps) {
  return (
    <div className={`relative flex-1 max-w-sm ${className ?? ''}`}>
      <Search className="absolute left-2.5 top-2.5 h-4 w-4 text-muted-foreground" />
      <Input
        placeholder={placeholder}
        value={value}
        onChange={(e) => onChange(e.target.value)}
        className="pl-8"
      />
    </div>
  )
}
