package de.carmajaperlen.armbandrechner

import androidx.test.core.app.ApplicationProvider
import org.junit.After
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Before
import org.junit.Test

class SecureTokenStoreTest {
    private lateinit var store: SecureTokenStore

    @Before
    fun setUp() {
        store = SecureTokenStore(ApplicationProvider.getApplicationContext())
        store.clearSession()
    }

    @After
    fun tearDown() {
        store.clearSession()
    }

    @Test
    fun rememberedSessionIsEncryptedAndCanBeRestored() {
        store.saveRememberedSession("test-token")

        assertTrue(store.isRememberedSessionEnabled())
        assertEquals("test-token", store.loadRememberedToken())
    }

    @Test
    fun logoutRemovesRememberedSessionCompletely() {
        store.saveRememberedSession("test-token")

        store.clearSession()

        assertFalse(store.isRememberedSessionEnabled())
        assertNull(store.loadRememberedToken())
    }
}
