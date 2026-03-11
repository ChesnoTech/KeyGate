import { Component, type ReactNode } from 'react'
import { useTranslation } from 'react-i18next'
import { AlertTriangle, RefreshCw } from 'lucide-react'
import { Button } from '@/components/ui/button'

interface Props {
  children: ReactNode
}

interface State {
  hasError: boolean
  error?: Error
}

class ErrorBoundaryInner extends Component<Props & { fallback: (error?: Error) => ReactNode }, State> {
  state: State = { hasError: false }

  static getDerivedStateFromError(error: Error): State {
    return { hasError: true, error }
  }

  componentDidCatch(error: Error, info: React.ErrorInfo) {
    console.error('Error Boundary caught:', error, info.componentStack)
  }

  render() {
    if (this.state.hasError) {
      return this.props.fallback(this.state.error)
    }
    return this.props.children
  }
}

function ErrorFallback({ error }: { error?: Error }) {
  const { t } = useTranslation()

  return (
    <div className="flex flex-1 items-center justify-center p-8">
      <div className="flex flex-col items-center gap-4 text-center max-w-md">
        <div className="rounded-full bg-destructive/10 p-4">
          <AlertTriangle className="h-8 w-8 text-destructive" />
        </div>
        <h2 className="text-xl font-semibold">
          {t('error.boundary_title', 'Something went wrong')}
        </h2>
        <p className="text-sm text-muted-foreground">
          {t('error.boundary_description', 'An unexpected error occurred. Please try refreshing the page.')}
        </p>
        {error && (
          <pre className="mt-2 max-w-full overflow-auto rounded-md bg-muted p-3 text-xs text-left">
            {error.message}
          </pre>
        )}
        <Button onClick={() => window.location.reload()} className="mt-2">
          <RefreshCw className="mr-2 h-4 w-4" />
          {t('error.boundary_refresh', 'Refresh Page')}
        </Button>
      </div>
    </div>
  )
}

export function ErrorBoundary({ children }: Props) {
  return (
    <ErrorBoundaryInner fallback={(error) => <ErrorFallback error={error} />}>
      {children}
    </ErrorBoundaryInner>
  )
}
