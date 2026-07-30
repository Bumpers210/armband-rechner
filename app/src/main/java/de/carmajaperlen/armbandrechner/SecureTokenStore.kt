package de.carmajaperlen.armbandrechner

import android.content.Context
import android.security.keystore.KeyGenParameterSpec
import android.security.keystore.KeyProperties
import android.util.Base64
import java.security.KeyStore
import javax.crypto.Cipher
import javax.crypto.KeyGenerator
import javax.crypto.SecretKey
import javax.crypto.spec.GCMParameterSpec

class SecureTokenStore(context: Context) {
    private val preferences = context.getSharedPreferences("product_api_auth", Context.MODE_PRIVATE)

    fun saveRememberedSession(token: String) {
        val cipher = Cipher.getInstance(TRANSFORMATION)
        cipher.init(Cipher.ENCRYPT_MODE, getOrCreateKey())
        val encrypted = cipher.doFinal(token.toByteArray(Charsets.UTF_8))
        preferences.edit()
            .putString(KEY_TOKEN, Base64.encodeToString(encrypted, Base64.NO_WRAP))
            .putString(KEY_IV, Base64.encodeToString(cipher.iv, Base64.NO_WRAP))
            .putBoolean(KEY_REMEMBER_SESSION, true)
            .apply()
    }

    fun loadRememberedToken(): String? {
        if (!isRememberedSessionEnabled()) return null

        val encodedToken = preferences.getString(KEY_TOKEN, null) ?: return null
        val encodedIv = preferences.getString(KEY_IV, null) ?: return null

        return runCatching {
            val cipher = Cipher.getInstance(TRANSFORMATION)
            cipher.init(
                Cipher.DECRYPT_MODE,
                getOrCreateKey(),
                GCMParameterSpec(128, Base64.decode(encodedIv, Base64.NO_WRAP)),
            )
            String(
                cipher.doFinal(Base64.decode(encodedToken, Base64.NO_WRAP)),
                Charsets.UTF_8,
            )
        }.getOrNull()
    }

    fun isRememberedSessionEnabled(): Boolean {
        return preferences.getBoolean(KEY_REMEMBER_SESSION, false)
    }

    fun clearSession() {
        preferences.edit()
            .remove(KEY_TOKEN)
            .remove(KEY_IV)
            .remove(KEY_REMEMBER_SESSION)
            .apply()
    }

    fun savePlainSetting(key: String, value: String) {
        preferences.edit().putString(key, value).apply()
    }

    fun loadPlainSetting(key: String, fallback: String = ""): String {
        return preferences.getString(key, fallback) ?: fallback
    }

    private fun getOrCreateKey(): SecretKey {
        val keyStore = KeyStore.getInstance(ANDROID_KEYSTORE).apply { load(null) }
        val existing = keyStore.getKey(KEY_ALIAS, null)

        if (existing is SecretKey) {
            return existing
        }

        val keyGenerator = KeyGenerator.getInstance(
            KeyProperties.KEY_ALGORITHM_AES,
            ANDROID_KEYSTORE,
        )
        keyGenerator.init(
            KeyGenParameterSpec.Builder(
                KEY_ALIAS,
                KeyProperties.PURPOSE_ENCRYPT or KeyProperties.PURPOSE_DECRYPT,
            )
                .setBlockModes(KeyProperties.BLOCK_MODE_GCM)
                .setEncryptionPaddings(KeyProperties.ENCRYPTION_PADDING_NONE)
                .setRandomizedEncryptionRequired(true)
                .build(),
        )
        return keyGenerator.generateKey()
    }

    companion object {
        const val SETTING_API_BASE_URL = "api_base_url"
        const val SETTING_DEVICE_NAME = "device_name"

        private const val ANDROID_KEYSTORE = "AndroidKeyStore"
        private const val KEY_ALIAS = "carmaja_product_api_token"
        private const val KEY_TOKEN = "encrypted_token"
        private const val KEY_IV = "encrypted_token_iv"
        private const val KEY_REMEMBER_SESSION = "remember_session"
        private const val TRANSFORMATION = "AES/GCM/NoPadding"
    }
}
