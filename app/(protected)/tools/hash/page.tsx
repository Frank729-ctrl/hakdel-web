'use client'
import { ToolShell } from '@/components/tools/tool-shell'

async function lookup(hash: string) {
  const res = await fetch('/api/tools/hash', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ hash }) })
  if (!res.ok) throw new Error('Lookup failed')
  return res.json()
}

export default function HashPage() {
  return (
    <ToolShell title="Hash Check" description="Look up a file hash against VirusTotal and MalwareBazaar."
      inputLabel="MD5 / SHA1 / SHA256 hash" placeholder="d8e8fca2dc0f896fd7cb4cb0031ba249" buttonLabel="Check Hash" onSubmit={lookup}
      renderResult={(data: any) => (
        <div className="space-y-4">
          {data.virustotal?.data && (
            <div className="card p-5">
              <div className="section-title">VirusTotal</div>
              <div className="grid grid-cols-4 gap-3">
                {Object.entries(data.virustotal.data.attributes?.last_analysis_stats ?? {}).map(([k, v]) => (
                  <div key={k} className="text-center p-3 bg-surface-2 rounded-lg">
                    <div className={`text-xl font-bold font-mono ${k === 'malicious' ? 'text-danger' : k === 'suspicious' ? 'text-warning' : 'text-success'}`}>{String(v)}</div>
                    <div className="text-xs text-muted capitalize">{k}</div>
                  </div>
                ))}
              </div>
            </div>
          )}
          {data.malwarebazaar?.data?.[0] && (
            <div className="card p-5">
              <div className="section-title">MalwareBazaar</div>
              <div className="grid grid-cols-2 gap-3 text-sm">
                {[['File Name', data.malwarebazaar.data[0].file_name], ['MIME Type', data.malwarebazaar.data[0].file_type_mime],
                  ['Signature', data.malwarebazaar.data[0].signature], ['First Seen', data.malwarebazaar.data[0].first_seen]].map(([k, v]) => (
                  <div key={String(k)}><div className="text-xs text-muted mb-0.5">{k}</div><div className="font-mono text-ink">{v ?? '—'}</div></div>
                ))}
              </div>
            </div>
          )}
        </div>
      )}
    />
  )
}
