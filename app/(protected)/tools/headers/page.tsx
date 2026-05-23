'use client'
import { ToolShell } from '@/components/tools/tool-shell'
import { CheckCircle, XCircle } from 'lucide-react'

async function analyze(url: string) {
  const res = await fetch('/api/tools/headers', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ url }) })
  if (!res.ok) throw new Error('Request failed')
  return res.json()
}

export default function HeadersPage() {
  return (
    <ToolShell title="HTTP Headers" description="Analyze security headers for any URL."
      inputLabel="URL" inputType="url" placeholder="https://example.com" buttonLabel="Check Headers" onSubmit={analyze}
      renderResult={(data: any) => (
        <div className="space-y-4">
          <div className="card p-5">
            <div className="section-title">Security Headers</div>
            <div className="space-y-2">
              {data.analysis?.map((h: any) => (
                <div key={h.header} className="flex items-start gap-3 py-2 border-b border-border last:border-0">
                  {h.present ? <CheckCircle size={15} className="text-success mt-0.5 flex-shrink-0" /> : <XCircle size={15} className="text-danger mt-0.5 flex-shrink-0" />}
                  <div className="flex-1 min-w-0">
                    <div className="font-mono text-xs text-ink">{h.header}</div>
                    {h.value && <div className="text-xs text-muted mt-0.5 truncate">{h.value}</div>}
                  </div>
                  <span className={h.present ? 'badge-green' : 'badge-red'}>{h.present ? 'Present' : 'Missing'}</span>
                </div>
              ))}
            </div>
          </div>
        </div>
      )}
    />
  )
}
