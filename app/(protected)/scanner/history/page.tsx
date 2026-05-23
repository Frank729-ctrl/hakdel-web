import { getServerSession } from 'next-auth'
import { authOptions } from '@/lib/auth'
import { prisma } from '@/lib/db'
import { Header } from '@/components/layout/header'
import { formatRelative, scoreToGrade } from '@/lib/utils'
import Link from 'next/link'
import { Shield } from 'lucide-react'
import type { Metadata } from 'next'

export const metadata: Metadata = { title: 'Scan History' }

export default async function ScanHistoryPage() {
  const session = await getServerSession(authOptions)!
  const scans = await prisma.scan.findMany({
    where: { userId: session!.user.id },
    orderBy: { createdAt: 'desc' },
    take: 100,
  })

  return (
    <div>
      <Header title="Scan History" description={`${scans.length} scan${scans.length !== 1 ? 's' : ''} total.`}
        action={<Link href="/scanner" className="btn-primary"><Shield size={14} /> New Scan</Link>} />
      {scans.length === 0 ? (
        <div className="card p-12 text-center">
          <Shield size={32} className="text-muted mx-auto mb-3" />
          <div className="text-ink font-medium mb-1">No scans yet</div>
          <p className="text-sm text-muted mb-4">Run your first security scan to see results here.</p>
          <Link href="/scanner" className="btn-primary">Start scanning →</Link>
        </div>
      ) : (
        <div className="card">
          <div className="divide-y divide-border">
            {scans.map((scan) => {
              const { grade, color } = scoreToGrade(scan.score ?? 0)
              return (
                <div key={scan.id} className="flex items-center gap-4 px-5 py-3 hover:bg-surface-2/50 transition-colors">
                  <div className={`w-12 h-12 rounded-xl bg-surface-2 flex flex-col items-center justify-center flex-shrink-0 ${color}`}>
                    <div className="text-lg font-bold font-mono">{scan.score !== null ? grade : '—'}</div>
                    {scan.score !== null && <div className="text-[10px] text-muted">{scan.score}</div>}
                  </div>
                  <div className="flex-1 min-w-0">
                    <div className="font-mono text-sm font-medium text-ink truncate">{scan.targetUrl}</div>
                    <div className="text-xs text-muted mt-0.5">{formatRelative(scan.createdAt)} · {scan.profile}</div>
                  </div>
                  <span className={`badge ${ scan.status === 'DONE' ? 'badge-green' : scan.status === 'ERROR' ? 'badge-red' : 'badge-gray'}`}>
                    {scan.status.toLowerCase()}
                  </span>
                </div>
              )
            })}
          </div>
        </div>
      )}
    </div>
  )
}
