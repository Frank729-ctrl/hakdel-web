'use client'

import { useState } from 'react'
import { useRouter } from 'next/navigation'
import { useSession } from 'next-auth/react'
import { Header } from '@/components/layout/header'
import { Shield, Play, Clock, AlertCircle, CheckCircle, Loader } from 'lucide-react'
import Link from 'next/link'

const MODULES = [
  { id: 'headers', label: 'Security Headers' },
  { id: 'ssl', label: 'TLS / SSL' },
  { id: 'ports', label: 'Port Scan' },
  { id: 'dns', label: 'DNS Enum' },
  { id: 'xss', label: 'XSS Detection' },
  { id: 'sqli', label: 'SQLi Detection' },
  { id: 'cve', label: 'CVE Matching' },
  { id: 'cms', label: 'CMS Detection' },
]

const PROFILES = [
  { id: 'quick', label: 'Quick', desc: '~2 min, core checks', modules: ['headers', 'ssl', 'dns'] },
  { id: 'full', label: 'Full', desc: '~8 min, all modules', modules: MODULES.map((m) => m.id) },
  { id: 'custom', label: 'Custom', desc: 'Choose modules', modules: [] },
]

type Status = 'idle' | 'scanning' | 'done' | 'error'

interface Finding {
  severity: string
  title: string
  description: string
  module: string
}

interface ScanResult {
  score: number
  grade: string
  summary: string
  findings: Finding[]
}

export default function ScannerPage() {
  const { data: session } = useSession()
  const router = useRouter()
  const [url, setUrl] = useState('')
  const [profile, setProfile] = useState('quick')
  const [modules, setModules] = useState<string[]>([])
  const [status, setStatus] = useState<Status>('idle')
  const [progress, setProgress] = useState(0)
  const [result, setResult] = useState<ScanResult | null>(null)
  const [error, setError] = useState('')
  const [jobId, setJobId] = useState('')

  const isPro = session?.user?.plan === 'PRO' || session?.user?.role === 'ADMIN'

  const toggleModule = (id: string) =>
    setModules((prev) => prev.includes(id) ? prev.filter((m) => m !== id) : [...prev, id])

  const activeModules = profile === 'custom' ? modules : PROFILES.find((p) => p.id === profile)?.modules ?? []

  async function startScan(e: React.FormEvent) {
    e.preventDefault()
    if (!url) return
    setStatus('scanning')
    setProgress(0)
    setResult(null)
    setError('')

    try {
      const startRes = await fetch('/api/scanner/start', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ url, profile, modules: activeModules }),
      })
      const { job_id, error: startErr } = await startRes.json()
      if (startErr || !job_id) throw new Error(startErr ?? 'Failed to start scan')
      setJobId(job_id)

      // Poll for completion
      const poll = setInterval(async () => {
        const statusRes = await fetch(`/api/scanner/status/${job_id}`)
        const data = await statusRes.json()
        setProgress(data.progress ?? 0)

        if (data.status === 'done') {
          clearInterval(poll)
          setResult(data.result)
          setStatus('done')

          // Save to DB
          await fetch('/api/scanner/save', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
              job_id,
              target_url: url,
              profile,
              modules: activeModules,
              score: data.result?.score,
              grade: data.result?.grade,
              summary: data.result?.summary,
              result: data.result,
            }),
          })
        } else if (data.status === 'error') {
          clearInterval(poll)
          setError(data.message ?? 'Scan failed.')
          setStatus('error')
        }
      }, 2000)
    } catch (err: any) {
      setError(err.message)
      setStatus('error')
    }
  }

  const severityOrder: Record<string, number> = { critical: 0, high: 1, medium: 2, low: 3, info: 4 }
  const sortedFindings = result?.findings?.slice().sort(
    (a, b) => (severityOrder[a.severity] ?? 5) - (severityOrder[b.severity] ?? 5)
  )

  return (
    <div>
      <Header
        title="Security Scanner"
        description="Scan a target for vulnerabilities and misconfigurations."
        action={
          <Link href="/scanner/history" className="btn-secondary">
            <Clock size={14} /> History
          </Link>
        }
      />

      <div className="grid lg:grid-cols-[1fr_380px] gap-6">
        {/* Left: Form */}
        <div className="space-y-4">
          <form onSubmit={startScan} className="card p-5 space-y-4">
            <div>
              <label className="label">Target URL</label>
              <input
                className="input font-mono"
                type="url"
                placeholder="https://example.com"
                value={url}
                onChange={(e) => setUrl(e.target.value)}
                required
                disabled={status === 'scanning'}
              />
            </div>

            <div>
              <label className="label">Scan Profile</label>
              <div className="grid grid-cols-3 gap-2">
                {PROFILES.map((p) => (
                  <button
                    key={p.id}
                    type="button"
                    onClick={() => setProfile(p.id)}
                    className={`p-3 rounded-lg border text-left transition-colors ${
                      profile === p.id
                        ? 'bg-accent/10 border-accent/30 text-ink'
                        : 'bg-surface-2 border-border text-dim hover:text-ink hover:border-border-2'
                    }`}
                    disabled={status === 'scanning'}
                  >
                    <div className="text-sm font-medium">{p.label}</div>
                    <div className="text-xs mt-0.5 opacity-60">{p.desc}</div>
                  </button>
                ))}
              </div>
            </div>

            {profile === 'custom' && (
              <div>
                <label className="label">Modules</label>
                <div className="grid grid-cols-2 gap-2">
                  {MODULES.map((m) => (
                    <label key={m.id} className="flex items-center gap-2 cursor-pointer">
                      <input
                        type="checkbox"
                        className="accent-accent"
                        checked={modules.includes(m.id)}
                        onChange={() => toggleModule(m.id)}
                        disabled={status === 'scanning'}
                      />
                      <span className="text-sm text-dim">{m.label}</span>
                    </label>
                  ))}
                </div>
              </div>
            )}

            <button type="submit" className="btn-primary w-full" disabled={status === 'scanning'}>
              {status === 'scanning' ? (
                <><Loader size={14} className="animate-spin" /> Scanning… {progress}%</>
              ) : (
                <><Play size={14} /> Start Scan</>
              )}
            </button>
          </form>

          {/* Progress bar while scanning */}
          {status === 'scanning' && (
            <div className="card p-5">
              <div className="flex items-center justify-between mb-2">
                <span className="text-sm text-ink font-medium">Scanning {url}</span>
                <span className="text-sm font-mono text-dim">{progress}%</span>
              </div>
              <div className="h-2 bg-border-2 rounded-full overflow-hidden">
                <div
                  className="h-full bg-gradient-to-r from-accent to-accent-2 rounded-full transition-all duration-500"
                  style={{ width: `${progress}%` }}
                />
              </div>
              <p className="text-xs text-muted mt-2">Running {activeModules.length} module{activeModules.length !== 1 ? 's' : ''}…</p>
            </div>
          )}

          {/* Error */}
          {status === 'error' && (
            <div className="card p-5 border-danger/20 bg-danger/5">
              <div className="flex items-center gap-2 text-danger mb-1">
                <AlertCircle size={16} /> <span className="font-medium">Scan failed</span>
              </div>
              <p className="text-sm text-dim">{error}</p>
            </div>
          )}

          {/* Results */}
          {status === 'done' && result && (
            <div className="space-y-4">
              {/* Score card */}
              <div className="card p-5">
                <div className="flex items-center gap-6">
                  <div className="w-20 h-20 rounded-2xl bg-surface-2 flex flex-col items-center justify-center flex-shrink-0">
                    <div className={`text-3xl font-bold font-mono ${
                      result.score >= 80 ? 'text-success' : result.score >= 60 ? 'text-warning' : 'text-danger'
                    }`}>
                      {result.grade}
                    </div>
                    <div className="text-xs text-muted">{result.score}/100</div>
                  </div>
                  <div>
                    <div className="flex items-center gap-2 mb-1">
                      <CheckCircle size={14} className="text-success" />
                      <span className="text-sm font-medium text-ink">Scan complete</span>
                    </div>
                    <p className="text-sm text-dim">{result.summary}</p>
                  </div>
                </div>
              </div>

              {/* Findings */}
              {sortedFindings && sortedFindings.length > 0 && (
                <div className="card">
                  <div className="px-5 py-3 border-b border-border font-medium text-sm text-ink">
                    {sortedFindings.length} Finding{sortedFindings.length !== 1 ? 's' : ''}
                  </div>
                  <div className="divide-y divide-border">
                    {sortedFindings.map((f, i) => (
                      <div key={i} className="px-5 py-3">
                        <div className="flex items-center gap-2 mb-1">
                          <span className={`severity-${f.severity.toLowerCase()}`}>{f.severity}</span>
                          <span className="text-sm font-medium text-ink">{f.title}</span>
                        </div>
                        <p className="text-xs text-dim">{f.description}</p>
                      </div>
                    ))}
                  </div>
                </div>
              )}
            </div>
          )}
        </div>

        {/* Right: Info panel */}
        <div className="space-y-4">
          <div className="card p-5">
            <div className="section-title">About the scanner</div>
            <div className="space-y-3 text-sm text-dim">
              <p>Runs against the FastAPI scanner backend ({process.env.NEXT_PUBLIC_SCANNER_URL ?? 'hakdel.onrender.com'}) which performs real security checks.</p>
              <p>Results are saved to your scan history and can be compared over time.</p>
            </div>
          </div>

          {!isPro && (
            <div className="card p-5 border-accent/20 bg-accent/5">
              <div className="text-sm font-medium text-ink mb-1">Free plan limit</div>
              <p className="text-xs text-dim mb-3">Free users can run 3 scans per day. Upgrade to Pro for unlimited scans and advanced modules.</p>
              <Link href="/upgrade" className="btn-primary w-full text-xs py-1.5">
                Upgrade to Pro →
              </Link>
            </div>
          )}
        </div>
      </div>
    </div>
  )
}
