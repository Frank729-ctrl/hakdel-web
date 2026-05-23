'use client'

import { ToolShell } from '@/components/tools/tool-shell'

async function lookup(ip: string) {
  const res = await fetch('/api/tools/ip', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ ip }),
  })
  if (!res.ok) throw new Error('Lookup failed')
  return res.json()
}

export default function IpPage() {
  return (
    <ToolShell
      title="IP Reputation"
      description="Check an IP address against AbuseIPDB, VirusTotal, and Shodan."
      inputLabel="IP Address"
      placeholder="1.2.3.4"
      onSubmit={lookup}
      renderResult={(data: any) => (
        <div className="space-y-4">
          {data.abuseipdb?.data && (
            <div className="card p-5">
              <div className="section-title">AbuseIPDB</div>
              <div className="grid grid-cols-2 gap-3 text-sm">
                {[
                  ['Abuse Score', `${data.abuseipdb.data.abuseConfidenceScore}%`],
                  ['Total Reports', data.abuseipdb.data.totalReports],
                  ['Country', data.abuseipdb.data.countryCode],
                  ['ISP', data.abuseipdb.data.isp],
                  ['Usage Type', data.abuseipdb.data.usageType],
                  ['Domain', data.abuseipdb.data.domain],
                ].map(([k, v]) => (
                  <div key={String(k)}>
                    <div className="text-xs text-muted mb-0.5">{k}</div>
                    <div className="font-mono text-ink">{v ?? '—'}</div>
                  </div>
                ))}
              </div>
              {data.abuseipdb.data.abuseConfidenceScore > 0 && (
                <div className="mt-3 flex items-center gap-2">
                  <div className="h-2 flex-1 bg-border-2 rounded-full overflow-hidden">
                    <div
                      className="h-full bg-danger rounded-full"
                      style={{ width: `${data.abuseipdb.data.abuseConfidenceScore}%` }}
                    />
                  </div>
                  <span className="text-xs text-danger font-mono">{data.abuseipdb.data.abuseConfidenceScore}% abusive</span>
                </div>
              )}
            </div>
          )}

          {data.virustotal?.data && (
            <div className="card p-5">
              <div className="section-title">VirusTotal</div>
              <div className="grid grid-cols-4 gap-3">
                {Object.entries(data.virustotal.data.attributes?.last_analysis_stats ?? {}).map(([k, v]) => (
                  <div key={k} className="text-center p-3 bg-surface-2 rounded-lg">
                    <div className={`text-xl font-bold font-mono ${k === 'malicious' ? 'text-danger' : k === 'suspicious' ? 'text-warning' : 'text-success'}`}>
                      {String(v)}
                    </div>
                    <div className="text-xs text-muted capitalize">{k}</div>
                  </div>
                ))}
              </div>
            </div>
          )}

          {data.shodan?.ports && (
            <div className="card p-5">
              <div className="section-title">Shodan</div>
              <div className="flex flex-wrap gap-2">
                {data.shodan.ports.map((p: number) => (
                  <span key={p} className="px-2 py-1 bg-surface-2 border border-border rounded font-mono text-xs text-dim">{p}</span>
                ))}
              </div>
              {data.shodan.org && (
                <div className="mt-3 text-sm text-dim">
                  Org: <span className="text-ink">{data.shodan.org}</span>
                  {data.shodan.country_name && <> · {data.shodan.country_name}</>}
                </div>
              )}
            </div>
          )}
        </div>
      )}
    />
  )
}
