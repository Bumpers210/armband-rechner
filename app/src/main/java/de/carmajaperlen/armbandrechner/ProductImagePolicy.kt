package de.carmajaperlen.armbandrechner

/** Larger photos are enabled only in the isolated test app for now. */
internal data class ProductImagePolicy(val maxEdge: Int, val maxBytes: Int) {
    fun scaledDimensions(width: Int, height: Int): Pair<Int, Int> {
        require(width > 0 && height > 0) { "Bildabmessungen sind ungültig." }
        val largest = maxOf(width, height)
        if (largest <= maxEdge) return width to height
        return maxOf(1, (width.toLong() * maxEdge / largest).toInt()) to
            maxOf(1, (height.toLong() * maxEdge / largest).toInt())
    }

    companion object {
        fun forTarget(target: String): ProductImagePolicy = when (target) {
            "test" -> ProductImagePolicy(3200, 5 * 1_048_576)
            "production" -> ProductImagePolicy(1600, 1_048_576)
            else -> error("Unbekanntes Bildziel.")
        }
    }
}
