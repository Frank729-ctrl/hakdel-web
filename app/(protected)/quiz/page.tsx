import { Header } from '@/components/layout/header'
import { prisma } from '@/lib/db'
import Link from 'next/link'
import type { Metadata } from 'next'

export const metadata: Metadata = { title: 'Quiz Bank' }

export default async function QuizPage() {
  const categories = await prisma.quizCategory.findMany({
    include: { _count: { select: { questions: true, attempts: true } } },
  })

  return (
    <div>
      <Header title="CEH v13 Quiz Bank" description="1,065 questions across all 10 exam domains." />

      {categories.length === 0 ? (
        <div className="card p-12 text-center">
          <div className="text-3xl mb-3">📚</div>
          <div className="font-medium text-ink mb-1">Quiz bank coming soon</div>
          <p className="text-sm text-muted">Questions are being loaded. Check back soon.</p>
        </div>
      ) : (
        <div className="grid sm:grid-cols-2 lg:grid-cols-3 gap-4">
          {categories.map((cat) => (
            <Link key={cat.id} href={`/quiz/${cat.slug}`} className="card p-5 hover:border-accent/40 transition-all group">
              <div className="font-medium text-ink group-hover:text-accent transition-colors mb-1">{cat.title}</div>
              {cat.description && <p className="text-xs text-dim mb-3">{cat.description}</p>}
              <div className="flex items-center justify-between text-xs text-muted">
                <span className="font-mono">{cat._count.questions} questions</span>
                <span>{cat.domain}</span>
              </div>
            </Link>
          ))}
        </div>
      )}
    </div>
  )
}
