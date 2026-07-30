package de.carmajaperlen.armbandrechner

internal sealed interface ProductDraftDiscardResult {
    data object Unchanged : ProductDraftDiscardResult

    data class Remove(
        val draftId: String,
    ) : ProductDraftDiscardResult

    data class Restore(
        val draft: ProductDraft,
    ) : ProductDraftDiscardResult
}

internal class ProductDraftSession {
    private val savedDrafts = mutableMapOf<String, ProductDraft>()
    private val newDraftIds = mutableSetOf<String>()
    private val changedDraftIds = mutableSetOf<String>()

    val unsavedDraftIds: Set<String>
        get() = changedDraftIds.toSet()

    fun initialize(drafts: List<ProductDraft>) {
        savedDrafts.clear()
        savedDrafts.putAll(drafts.associateBy(ProductDraft::draftId))
        newDraftIds.clear()
        changedDraftIds.clear()
    }

    fun registerNew(draft: ProductDraft) {
        newDraftIds += draft.draftId
        changedDraftIds += draft.draftId
    }

    fun markChanged(draftId: String) {
        changedDraftIds += draftId
    }

    fun markSaved(draft: ProductDraft) {
        savedDrafts[draft.draftId] = draft
        newDraftIds -= draft.draftId
        changedDraftIds -= draft.draftId
    }

    fun discard(draftId: String): ProductDraftDiscardResult {
        if (!changedDraftIds.remove(draftId)) {
            return ProductDraftDiscardResult.Unchanged
        }
        if (newDraftIds.remove(draftId)) {
            return ProductDraftDiscardResult.Remove(draftId)
        }
        return savedDrafts[draftId]
            ?.let(ProductDraftDiscardResult::Restore)
            ?: ProductDraftDiscardResult.Remove(draftId)
    }
}
