import org.jetbrains.kotlin.gradle.dsl.JvmTarget

plugins {
    id("com.android.application")
    id("org.jetbrains.kotlin.android")%NATIVE_NOTIF_GRADLE_APP_PLUGIN%
}

android {
    namespace = "%NATIVE_APPLICATION_ID%"
    compileSdk = 36

    defaultConfig {
        applicationId = "%NATIVE_APPLICATION_ID%"
        minSdk = 28
        targetSdk = 36
        versionCode = 1
        versionName = "1.0"
    }

    buildTypes {
        release {
            isMinifyEnabled = false
        }
    }
    compileOptions {
        sourceCompatibility = JavaVersion.VERSION_17
        targetCompatibility = JavaVersion.VERSION_17
    }
    packaging {
        resources {
            excludes += setOf(
                "META-INF/versions/9/OSGI-INF/MANIFEST.MF",
                "META-INF/DEPENDENCIES",
                "META-INF/LICENSE*",
                "META-INF/NOTICE*",
            )
        }
    }
}

kotlin {
    compilerOptions {
        jvmTarget.set(JvmTarget.JVM_17)
    }
}%NATIVE_NOTIF_GRADLE_APP_CONFIG%

dependencies {
    implementation("dev.hotwire:core:1.2.7")
    implementation("dev.hotwire:navigation-fragments:1.2.7")
    implementation("com.google.android.material:material:1.12.0")
    implementation("com.github.joemasilotti:bridge-components:0.13.2")%NATIVE_NOTIF_GRADLE_APP_DEPS%%NATIVE_BARCODE_GRADLE_DEPS%%NATIVE_ADMOB_GRADLE_DEPS%
}
