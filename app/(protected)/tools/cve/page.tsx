'use client'

import { ToolShell } from '@/components/tools/tool-shell'
import { formatDate } from '@/lib/utils'

async function lookup(query: string) {
  const res = await fetch('/api/tools/cve', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ query }),
  })
  if (!res.ok) throw new Error('CVE lookup failed')
  return res.json()
}

function severityColor(s: string) {
  const m: Record<string, string> = { CRITICAL: 'badge-red', HIGH: 'badge-red', MEDIUM: 'badge-yellow', LOW: 'badge-blue', NONE: 'badge-gray' }
  return m[s?.toUpperCase()] ?? 'badge-gray'
}

export default function CvePage() {
  return (
    <ToolShell
      title="CVE Lookup"
      description="Search the NVD database by CVE ID or keyword."
      inputLabel="CVE ID or keyword"
      placeholder="CVE-2024-1234 or 'apache log4j'"
      onSubmit={lookup}
      renderResult={(data: any) => {
        const vulns = data.vulnerabilities ?? []
        if (!vulns.length) return <div className="card p-5 text-sm text-dim text-center">No results found.</div>

        return (
          <div className="space-y-3">
            <div className="text-xs text-muted">{data.totalResults ?? vulns.length} result{vulns.length !== 1 ? 's' : ''}</div>
            {vulns.map(({ cve }: any) => {
              const score = cve.metrics?.cvssMetricV31?.[0]?.cvssData?.baseScore
              const severity = cve.metrics?.cvssMetricV31?.[0]?.cvssData?.baseSeverity
              const desc = cve.descriptions?.find((d: any) => d.lang === 'en')?.value ?? ''
              return (
                <div key={cve.id} className="card p-5">
                  <div className="flex items-start justify-between gap-4 mb-2">
                    <a href={`https://nvd.nist.gov/vuln/detail/${cve.id}`} target="_blank" rel="noopener noreferrer"
                      className="font-mono text-accent font-medium hover:underline">{cve.id}</a>
                    <div className="flex items-center gap-2 flex-shrink-0">
                      {score && <span className="font-mono text-sm font-bold text-ink">{score}</span>}
                      {severity && <span className={severityColor(severity)}>{severity}</span>}
                    </div>
                  </div>
                  <p className="text-sm text-dim leading-relaxed">{desc}</p>
                  <div className="flex gap-4 mt-3 text-xs text-muted">
                    <span>Published: {formatDate(cve.published)}</span>
                    <span>Status: {cve.vulnStatus}</span>
                  </div>
                </div>
              )
            })}
          </div>
        )
      }}
    />
  )
}
