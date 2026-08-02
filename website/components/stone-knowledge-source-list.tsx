import type { StoneKnowledgePublicSource } from "@/content/stone-knowledge-public";

type StoneKnowledgeSourceListProps = {
  sources: readonly StoneKnowledgePublicSource[];
};

export function StoneKnowledgeSourceList({
  sources,
}: StoneKnowledgeSourceListProps) {
  return (
    <section className="stone-knowledge-source-list" aria-labelledby="sources-heading">
      <h2 id="sources-heading">Quellen</h2>
      <ul>
        {sources.map((source) => (
          <li key={source.url}>
            <a href={source.url} rel="noreferrer">
              {source.title}
            </a>
            <span> – {source.publisher}</span>
          </li>
        ))}
      </ul>
    </section>
  );
}
