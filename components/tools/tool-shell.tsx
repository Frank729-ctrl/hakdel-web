'use client'

import { useState } from 'react'
import { Header } from '@/components/layout/header'
import { Loader, Search } from 'lucide-react'

interface ToolShellProps {
  title: string; description: string; placeholder: string; inputLabel: string;
  inputType?: string; buttonLabel?: string;
  onSubmit: (value: string) => Promise<unknown>
  renderResult: (data: unknown) => React.ReactNode
}

export function ToolShell({ title, description, placeholder, inputLabel, inputType = 'text', buttonLabel = 'Analyze', onSubmit, renderResult }: ToolShellProps) {
  const [value, setValue] = useState('')
  const [loading, setLoading] = useState(false)
  const [result, setResult] = useState<unknown>(null)
  const [error, setError] = useState('')

  async function handleSubmit(e: React.FormEvent) {
    e.preventDefault()
    if (!value.trim()) return
    setLoading(true); setError(''); setResult(null)
    try { setResult(await onSubmit(value.trim())) }
    catch (err: any) { setError(err.message ?? 'Request failed') }
    finally { setLoading(false) }
  }

  return (
    <div>
      <Header title={title} description={description} />
      <div className="max-w-3xl space-y-4">
        <form onSubmit={handleSubmit} className="card p-5 flex gap-3">
          <div className="flex-1">
            <label className="label">{inputLabel}</label>
            <input className="input font-mono" type={inputType} placeholder={placeholder}
              value={value} onChange={e => setValue(e.target.value)} required disabled={loading} />
          </div>
          <div className="flex items-end">
            <button type="submit" className="btn-primary" disabled={loading}>
              {loading ? <Loader size={14} className="animate-spin" /> : <Search size={14} />}
              {loading ? 'Loading…' : buttonLabel}
            </button>
          </div>
        </form>
        {error && <div className="p-4 rounded-lg bg-danger/10 border border-danger/20 text-danger text-sm">{error}</div>}
        {result != null && !loading && renderResult(result)}
      </div>
    </div>
  )
}
