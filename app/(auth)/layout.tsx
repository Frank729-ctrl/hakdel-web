export default function AuthLayout({ children }: { children: React.ReactNode }) {
  return (
    <div className="min-h-screen bg-bg flex">
      <div className="hidden lg:flex flex-col w-[480px] bg-surface border-r border-border p-12 relative overflow-hidden">
        <div className="absolute inset-0 bg-gradient-to-br from-accent/5 via-transparent to-accent-2/5" />
        <div className="absolute top-0 right-0 w-64 h-64 bg-accent/5 rounded-full blur-3xl" />
        <div className="absolute bottom-0 left-0 w-48 h-48 bg-accent-2/5 rounded-full blur-3xl" />
        <div className="relative z-10">
          <div className="flex items-center gap-2 mb-16">
            <div className="w-8 h-8 bg-accent rounded flex items-center justify-center text-white font-bold text-sm">H</div>
            <span className="font-bold text-lg text-ink">HakDel</span>
          </div>
          <div className="space-y-8">
            <div>
              <p className="text-xs font-mono text-muted mb-3">// PLATFORM CAPABILITIES</p>
              <h2 className="text-3xl font-bold text-ink leading-tight">
                Train like an attacker.<br />
                <span className="text-gradient">Defend like an analyst.</span>
              </h2>
              <p className="text-dim mt-4 text-sm leading-relaxed">
                Containerized exploitation labs, live network scanners, and a CEH-aligned drill bank.
              </p>
            </div>
            <div className="space-y-4">
              {[
                { icon: '⚡', title: 'Live exploitation labs', desc: 'Real CVEs. Real shells. Fresh containers per session.' },
                { icon: '🔍', title: 'Network scanner suite', desc: 'Port, header, TLS, DNS, subdomain — AI-synthesized findings.' },
                { icon: '📋', title: 'CEH v13 quiz bank', desc: '1,065 questions across all 10 exam domains.' },
                { icon: '🛡️', title: 'Threat intel feed', desc: 'Watchlist alerts, CVE digest, custom notifications.' },
              ].map((f) => (
                <div key={f.title} className="flex gap-3">
                  <div className="w-8 h-8 bg-surface-2 rounded flex items-center justify-center text-sm flex-shrink-0 mt-0.5">{f.icon}</div>
                  <div>
                    <div className="text-sm font-medium text-ink">{f.title}</div>
                    <div className="text-xs text-muted mt-0.5">{f.desc}</div>
                  </div>
                </div>
              ))}
            </div>
          </div>
        </div>
        <div className="relative z-10 mt-auto flex items-center justify-between">
          <span className="text-xs text-muted flex items-center gap-1.5">
            <span className="w-2 h-2 rounded-full bg-success animate-pulse-slow" />
            OPERATORS ONLINE
          </span>
          <span className="text-xs font-mono text-muted">v2.0.0</span>
        </div>
      </div>
      <div className="flex-1 flex items-center justify-center p-6">
        <div className="w-full max-w-[400px]">
          <div className="lg:hidden flex items-center gap-2 mb-8">
            <div className="w-8 h-8 bg-accent rounded flex items-center justify-center text-white font-bold text-sm">H</div>
            <span className="font-bold text-lg text-ink">HakDel</span>
          </div>
          {children}
        </div>
      </div>
    </div>
  )
}
