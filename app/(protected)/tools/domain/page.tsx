'use client'
import { ToolShell } from '@/components/tools/tool-shell'

async function lookup(domain: string) {
  const res = await fetch('/api/tools/domain', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ domain }) })
  if (!res.ok) throw new Error('Lookup failed')
  return res.json()
}

export default function DomainPage() {
  return (
    <ToolShell title="Domain Intelligence" description="Enumerate subdomains via certificate transparency logs and DNS."
      inputLabel="Domain" placeholder="example.com" buttonLabel="Enumerate" onSubmit={lookup}
      renderResult={(data: any) => (
        <div className="space-y-4">
          {data.subdomains?.length > 0 && (
            <div className="card p-5">
              <div className="section-title">{data.subdomains.length} Subdomains (via crt.sh)</div>
              <div className="grid grid-cols-2 gap-1 max-h-80 overflow-auto">
                {data.subdomains.map((s: string) => <div key={s} className="font-mono text-xs text-dim py-0.5">{s}</div>)}
              </div>
            </div>
          )}
          {data.dns && (
            <div className="card p-5">
              <div className="section-title">DNS Records</div>
              <pre className="mono text-xs text-dim whitespace-pre-wrap bg-surface-2 p-4 rounded-lg overflow-auto max-h-64">{data.dns}</pre>
            </div>
          )}
        </div>
      )}
    />
  )
}
