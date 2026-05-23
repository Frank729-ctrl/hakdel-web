import { NextRequest, NextResponse } from 'next/server'
import { getServerSession } from 'next-auth'
import { authOptions } from '@/lib/auth'
import { prisma } from '@/lib/db'

export async function POST(req: NextRequest) {
  const session = await getServerSession(authOptions)
  if (!session) return NextResponse.json({ error: 'Unauthorized' }, { status: 401 })

  const { job_id, target_url, profile, modules, score, grade, summary, result } = await req.json()

  const scan = await prisma.scan.create({
    data: {
      userId: session.user.id,
      targetUrl: target_url,
      profile: profile ?? 'quick',
      modules: modules ?? [],
      score,
      grade,
      summary,
      result,
      status: 'DONE',
      jobId: job_id,
    },
  })

  // Award XP for scan (10 XP per scan)
  await prisma.user.update({
    where: { id: session.user.id },
    data: { xp: { increment: 10 } },
  })

  return NextResponse.json({ ok: true, id: scan.id })
}
