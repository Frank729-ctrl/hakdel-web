'use client'
import { ToolShell } from '@/components/tools/tool-shell'

async function scan(target: string) {
  const res = await fetch('/api/tools/ports', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ target }) })
  if (!res.ok) throw new Error('Port scan failed')
  return res.json()
}

export default function PortsPage() {
  return (
    <ToolShell title="Port Scanner" description="Run an nmap scan via HackerTarget API."
      inputLabel="Target host or IP" placeholder="scanme.nmap.org" buttonLabel="Scan Ports" onSubmit={scan}
      renderResult={(data: any) => (
        <div className="card p-5">
          <div className="section-title">Nmap Output</div>
          <pre className="mono text-xs text-dim whitespace-pre-wrap leading-relaxed bg-surface-2 p-4 rounded-lg overflow-auto max-h-96">{data.raw}</pre>
        </div>
      )}
    />
  )
}
