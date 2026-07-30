package de.carmajaperlen.armbandrechner

import java.math.BigDecimal
import org.junit.Assert.assertEquals
import org.junit.Assert.assertThrows
import org.junit.Test

class GvizPriceListParserTest {
    @Test
    fun parsesCapturedRealGoogleResponse() {
        val response = checkNotNull(
            javaClass.classLoader?.getResource("real_price_list_response.txt"),
        ).readText()

        val prices = GvizPriceListParser.parse(response)

        assertEquals(18, prices.size)
        assertEquals("Rosenquarz", prices.first().name)
        assertEquals(0, BigDecimal("0.15").compareTo(prices.first().unitPrice))
        assertEquals("Lavaperlen", prices.last().name)
    }

    @Test
    fun rejectsWrongHeaders() {
        val response = validResponse().replace(
            "\"label\":\"Preis pro Stück\"",
            "\"label\":\"Preis\"",
        )

        assertThrows(PriceListFormatException::class.java) {
            GvizPriceListParser.parse(response)
        }
    }

    @Test
    fun rejectsNegativePrices() {
        val response = validResponse(price = "-0.15")

        assertThrows(PriceListFormatException::class.java) {
            GvizPriceListParser.parse(response)
        }
    }

    @Test
    fun rejectsDuplicateNamesIgnoringCase() {
        val rows =
            """{"c":[{"v":"Rosenquarz"},{"v":0.15},{"v":true}]},""" +
                """{"c":[{"v":"rosenquarz"},{"v":0.20},{"v":true}]}"""

        assertThrows(PriceListFormatException::class.java) {
            GvizPriceListParser.parse(validResponse(rows = rows))
        }
    }

    @Test
    fun ignoresEmptyAndInactiveRows() {
        val rows =
            """{"c":[null,null,null]},""" +
                """{"c":[{"v":"Inaktiv"},{"v":0.20},{"v":false}]},""" +
                """{"c":[{"v":"Aktiv"},{"v":0.30},{"v":true}]}"""

        val result = GvizPriceListParser.parse(validResponse(rows = rows))

        assertEquals(listOf("Aktiv"), result.map { it.name })
    }

    @Test
    fun rejectsDamagedResponse() {
        assertThrows(PriceListFormatException::class.java) {
            GvizPriceListParser.parse("keine gültige Antwort")
        }
    }

    private fun validResponse(
        price: String = "0.15",
        rows: String =
            """{"c":[{"v":"Rosenquarz"},{"v":$price},{"v":true}]}""",
    ): String {
        return """
            google.visualization.Query.setResponse({
              "status":"ok",
              "table":{
                "cols":[
                  {"label":"Name","type":"string"},
                  {"label":"Preis pro Stück","type":"number"},
                  {"label":"Aktiv","type":"boolean"}
                ],
                "rows":[$rows]
              }
            });
        """.trimIndent()
    }
}

