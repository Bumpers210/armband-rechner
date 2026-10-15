package de.carmajaperlen.armbandrechner

import org.junit.Assert.assertEquals
import org.junit.Test

class ProductImagePolicyTest {
    @Test fun testPhotosAllow3200PixelsAndFiveMiB() {
        val policy = ProductImagePolicy.forTarget("test")
        assertEquals(3200, policy.maxEdge)
        assertEquals(5_242_880, policy.maxBytes)
        assertEquals(3200 to 2400, policy.scaledDimensions(4032, 3024))
        assertEquals(2400 to 3200, policy.scaledDimensions(3024, 4032))
        assertEquals(1600 to 1490, policy.scaledDimensions(1600, 1490))
        assertEquals(3200 to 1, policy.scaledDimensions(6400, 1))
    }
    @Test fun productionLimitsAreUnchanged() {
        assertEquals(ProductImagePolicy(1600, 1_048_576), ProductImagePolicy.forTarget("production"))
    }
    @Test(expected = IllegalStateException::class) fun unknownTargetFailsClosed() {
        ProductImagePolicy.forTarget("unknown")
    }
    @Test(expected = IllegalArgumentException::class) fun invalidDimensionsAreRejected() {
        ProductImagePolicy.forTarget("test").scaledDimensions(-1, 0)
    }
}
