import java.util.Properties
import org.jetbrains.kotlin.gradle.dsl.JvmTarget

plugins {
    id("com.android.application")
    id("org.jetbrains.kotlin.plugin.compose")
}

val productionVersionCode = 1
val productionVersionName = "1.0.0"
val productionSigningPropertiesFile = rootProject.file(".signing/production-keystore.properties")
val productionSigningProperties = Properties().apply {
    if (productionSigningPropertiesFile.isFile) {
        productionSigningPropertiesFile.inputStream().use(::load)
    }
}
val productionSigningStoreFile = System.getenv("CARMAJA_PRODUCTION_KEYSTORE_PATH")
    ?.takeIf(String::isNotBlank)
    ?.let(rootProject::file)
    ?: productionSigningProperties.getProperty("storeFile")
        ?.takeIf(String::isNotBlank)
        ?.let { rootProject.file(".signing/$it") }
val productionSigningStorePassword = System.getenv("CARMAJA_PRODUCTION_STORE_PASSWORD")
    ?.takeIf(String::isNotBlank)
    ?: productionSigningProperties.getProperty("storePassword")?.takeIf(String::isNotBlank)
val productionSigningKeyPassword = System.getenv("CARMAJA_PRODUCTION_KEY_PASSWORD")
    ?.takeIf(String::isNotBlank)
    ?: productionSigningProperties.getProperty("keyPassword")?.takeIf(String::isNotBlank)
val productionSigningKeyAlias = "carmaja-product-management-production"
val productionSigningReady = productionSigningStoreFile?.isFile == true &&
    productionSigningStorePassword != null &&
    productionSigningKeyPassword != null

android {
    namespace = "de.carmajaperlen.armbandrechner"
    compileSdk = 36

    defaultConfig {
        applicationId = "de.carmajaperlen.armbandrechner"
        minSdk = 26
        targetSdk = 36
        versionCode = productionVersionCode
        versionName = productionVersionName

        testInstrumentationRunner = "androidx.test.runner.AndroidJUnitRunner"
        manifestPlaceholders["appLabel"] = "Carmaja-Perlen Produktverwaltung"
        buildConfigField(
            "String",
            "DEFAULT_PRODUCT_API_BASE_URL",
            "\"https://api.carmaja-perlen.de/\"",
        )
        buildConfigField("String", "PRODUCT_PUBLISH_TARGET", "\"production\"")
    }

    signingConfigs {
        if (productionSigningReady) {
            create("release") {
                storeFile = productionSigningStoreFile
                storePassword = productionSigningStorePassword
                keyAlias = productionSigningKeyAlias
                keyPassword = productionSigningKeyPassword
            }
        }
    }

    buildTypes {
        debug {
            manifestPlaceholders["appLabel"] = "Carmaja-Perlen Produktverwaltung"
        }

        release {
            isMinifyEnabled = false
            if (productionSigningReady) {
                signingConfig = signingConfigs.getByName("release")
            }
            proguardFiles(
                getDefaultProguardFile("proguard-android-optimize.txt"),
                "proguard-rules.pro",
            )
        }
    }

    buildFeatures {
        buildConfig = true
        compose = true
    }

    compileOptions {
        sourceCompatibility = JavaVersion.VERSION_17
        targetCompatibility = JavaVersion.VERSION_17
    }

    kotlin {
        compilerOptions {
            jvmTarget.set(JvmTarget.JVM_17)
        }
    }

    packaging {
        resources.excludes += "/META-INF/{AL2.0,LGPL2.1}"
    }
}

dependencies {
    val composeBom = platform("androidx.compose:compose-bom:2026.06.00")

    implementation(composeBom)
    androidTestImplementation(composeBom)

    implementation("androidx.activity:activity-compose:1.13.0")
    implementation("androidx.compose.foundation:foundation")
    implementation("androidx.compose.material:material-icons-core")
    implementation("androidx.compose.material3:material3")
    implementation("androidx.compose.ui:ui")
    implementation("androidx.compose.ui:ui-tooling-preview")
    implementation("androidx.datastore:datastore-preferences:1.2.1")
    implementation("androidx.lifecycle:lifecycle-runtime-compose:2.10.0")
    implementation("androidx.lifecycle:lifecycle-viewmodel-ktx:2.10.0")
    implementation("androidx.lifecycle:lifecycle-viewmodel-compose:2.10.0")
    implementation("org.jetbrains.kotlinx:kotlinx-coroutines-android:1.10.2")

    testImplementation("junit:junit:4.13.2")
    testImplementation("androidx.datastore:datastore-preferences-core:1.2.1")
    testImplementation("org.json:json:20250517")
    testImplementation("org.jetbrains.kotlinx:kotlinx-coroutines-test:1.10.2")

    androidTestImplementation("androidx.test.ext:junit:1.3.0")
    androidTestImplementation("androidx.test.espresso:espresso-core:3.7.0")
    androidTestImplementation("androidx.compose.ui:ui-test-junit4")
    debugImplementation("androidx.compose.ui:ui-test-manifest")
    debugImplementation("androidx.compose.ui:ui-tooling")
}
