package de.carmajaperlen.armbandrechner

import org.junit.Assert.assertEquals
import org.junit.Assert.assertThrows
import org.junit.Test

class ProductApiTargetTest {
    @Test
    fun productionEndpointAcceptsOnlyExactProductionApi() {
        assertEquals(
            "https://api.carmaja-perlen.de/",
            requireProductionApiBaseUrl("https://api.carmaja-perlen.de/"),
        )

        listOf(
            "https://test-api.carmaja-perlen.de/",
            "https://api.carmaja-perlen.de.example/",
            "https://user@api.carmaja-perlen.de/",
            "https://api.carmaja-perlen.de:443/",
            "http://api.carmaja-perlen.de/",
        ).forEach { endpoint ->
            assertThrows(ProductApiException::class.java) {
                requireProductionApiBaseUrl(endpoint)
            }
        }
    }

    @Test
    fun loginTargetMustBeProductionAndMatchApiResponse() {
        requireMatchingPublishTarget("production", "production")

        assertThrows(ProductTargetMismatchException::class.java) {
            requireMatchingPublishTarget("production", "test")
        }
        assertThrows(ProductTargetMismatchException::class.java) {
            requireMatchingPublishTarget("test", "test")
        }
    }
}
