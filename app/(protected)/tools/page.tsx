import { Header } from '@/components/layout/header'
import Link from 'next/link'
import type { Metadata } from 'next'

export const metadata: Metadata = { title: 'Tools' }

const tools = [
  { href: '/tools/ip', title: 'IP Reputation', desc: 'AbuseIPDB + VirusTotal + Shodan lookup', icon: '🔍', tags: ['network', 'threat-intel'] },
  { href: '/tools/ports', title: 'Port Scanner', desc: 'Remote nmap scan via HackerTarget', icon: '🔌', tags: ['network'] },
  { href: '/tools/headers', title: 'HTTP Headers', desc: 'Security header analysis (CSP, HSTS, etc.)', icon: '📋', tags: ['web'] },
  { href: '/tools/domain', title: 'Domain Intel', desc: 'Subdomain enum via crt.sh + DNS records', icon: '🌐', tags: ['recon', 'network'] },
  { href: '/tools/cve', title: 'CVE Lookup', desc: 'NVD database search by ID or keyword', icon: '🐛', tags: ['vuln'] },
  { href: '/tools/hash', title: 'Hash Check', desc: 'VirusTotal + MalwareBazaar file hash lookup', icon: '#️⃣', tags: ['malware'] },
  { href: '/tools/url', title: 'URL Check', desc: 'Phishing and reputation scan for URLs', icon: '🔗', tags: ['web', 'threat-intel'] },
  { href: '/tools/email', title: 'Email Check', desc: 'OSINT lookup and breach detection', icon: '📧', tags: ['osint'] },
  { href: '/tools/ai', title: 'AI Analysis', desc: 'Claude-powered security findings analysis', icon: '🤖', tags: ['ai'], pro: true },
  { href: '/tools/network', title: 'Network Map', desc: 'Route tracing and network topology', icon: '🗺️', tags: ['network'] },
  { href: '/tools/watchlist', title: 'Watchlist', desc: 'Monitor domains and IPs for changes', icon: '👁️', tags: ['monitoring'] },
]

export default function ToolsPage() {
  return (
    <div>
      <Header title="Security Tools" description="11 tools for recon, threat intel, and vulnerability analysis." />

      <div className="grid sm:grid-cols-2 lg:grid-cols-3 gap-4">
        {tools.map((t) => (
          <Link
            key={t.href}
            href={t.href}
            className="card p-5 hover:border-accent/40 hover:shadow-glow-sm transition-all group"
          >
            <div className="flex items-start gap-3">
              <div className="text-2xl flex-shrink-0">{t.icon}</div>
              <div className="flex-1 min-w-0">
                <div className="flex items-center gap-2 mb-1">
                  <span className="font-medium text-ink group-hover:text-accent transition-colors">{t.title}</span>
                  {t.pro && <span className="badge-blue text-[10px]">PRO</span>}
                </div>
                <p className="text-xs text-dim">{t.desc}</p>
                <div className="flex flex-wrap gap-1 mt-2">
                  {t.tags.map((tag) => (
                    <span key={tag} className="px-1.5 py-0.5 rounded text-[10px] bg-surface-2 text-muted font-mono">{tag}</span>
                  ))}
                </div>
              </div>
            </div>
          </Link>
        ))}
      </div>
    </div>
  )
}
