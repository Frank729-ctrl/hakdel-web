import { Header } from '@/components/layout/header'
import { CheckCircle } from 'lucide-react'
import type { Metadata } from 'next'

export const metadata: Metadata = { title: 'Upgrade to Pro' }

const features = [
  'Unlimited security scans (free: 3/day)',
  'All advanced scan modules',
  'AI-powered findings analysis',
  'Priority scan queue',
  'PDF scan reports',
  'All labs including Pro-tier',
  'Scheduled scans & watchlist alerts',
  'API access',
]

export default function UpgradePage() {
  return (
    <div>
      <Header title="Upgrade to Pro" description="Unlock the full HakDel platform." />
      <div className="max-w-lg">
        <div className="card p-8 border-accent/30 shadow-glow">
          <div className="text-center mb-6">
            <div className="badge-blue mx-auto mb-3">PRO PLAN</div>
            <div className="text-5xl font-bold font-mono text-ink mt-4">$12<span className="text-xl text-muted">/mo</span></div>
            <p className="text-sm text-muted mt-1">Cancel anytime. No hidden fees.</p>
          </div>

          <div className="space-y-3 mb-8">
            {features.map((f) => (
              <div key={f} className="flex items-center gap-3">
                <CheckCircle size={15} className="text-success flex-shrink-0" />
                <span className="text-sm text-dim">{f}</span>
              </div>
            ))}
          </div>

          <a
            href="/api/upgrade/checkout"
            className="btn-primary w-full text-center py-3 text-base"
          >
            Upgrade now →
          </a>

          <p className="text-center text-xs text-muted mt-3">Secure payment via Paystack</p>
        </div>
      </div>
    </div>
  )
}
