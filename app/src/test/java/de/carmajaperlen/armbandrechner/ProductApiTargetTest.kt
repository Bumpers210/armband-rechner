package de.carmajaperlen.armbandrechner

import org.junit.Assert.assertEquals
import org.junit.Assert.assertThrows
import org.junit.Test

class ProductApiTargetTest {
    @Test
    fun betaEndpointAcceptsOnlyExactTestApi() {
        assertEquals(
            "https://test-api.carmaja-perlen.de/",
            requireTestApiBaseUrl("https://test-api.carmaja-perlen.de/"),
        )

        listOf(
            "https://api.carmaja-perlen.de/",
            "https://test-api.carmaja-perlen.de.example/",
            "https://user@test-api.carmaja-perlen.de/",
            "https://test-api.carmaja-perlen.de:443/",
            "http://test-api.carmaja-perlen.de/",
        ).forEach { endpoint ->
            assertThrows(ProductApiException::class.java) {
                requireTestApiBaseUrl(endpoint)
            }
        }
    }

    @Test
    fun loginTargetMustBeTestAndMatchApiResponse() {
        requireMatchingPublishTarget("test", "test")

        assertThrows(ProductTargetMismatchException::class.java) {
            requireMatchingPublishTarget("test", "production")
        }
        assertThrows(ProductTargetMismatchException::class.java) {
            requireMatchingPublishTarget("production", "production")
        }
    }
}
